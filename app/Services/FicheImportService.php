<?php

namespace App\Services;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\TransportVehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Rebuilds a farm's pointage from its own Excel workbooks (see ImportFichesPointage):
 *  - each division fiche ("FICHE DE POINTAGE DES OUVRIERS", the three models PointageExport
 *    writes) gives, per worker, a "BLOC|ABREVIATION" code per day worked, SAL NET/J and the
 *    period's H.S. total — one present record per coded day, the H.S. spread over those days;
 *  - the "POINTAGE PAR TRANSPORT" workbook gives the équipes (one sheet per chef d'équipe,
 *    "Chef d'équipe : NAME" in A2) and, for a period without division fiches yet, who was present
 *    which day — recorded present with the operation "à préciser".
 * Workers are matched by CIN, else by NOM PRENOM; unknown ones from a division fiche are created.
 * A worker's division, complement and RIB follow the latest fiche they appear in.
 */
class FicheImportService
{
    private const BRUT = 97.44;

    /** Day-cell codes that are an operation on their own, with no bloc. */
    private const OPERATIONS_WITHOUT_BLOC = ['POINTEUR'];

    private array $report = [];

    private array $employeesByCin = [];

    private array $employeesByName = [];

    private array $operations = [];

    private array $blocs = [];

    private int $nextMatricule = 1;

    /** employee id => [rank of the latest fiche applied, attributes] */
    private array $latest = [];

    public function __construct(private PayrollService $payroll, private TransportService $transport) {}

    /**
     * @param  array  $periods  [['label', 'start', 'end', 'fiches' => [[path, enterprise_id], ...], 'presence' => path|null], ...] in date order
     * @param  string  $teamsFile  the transport workbook the équipes are taken from
     * @param  array  $newOperations  abbreviation => name, created when missing
     */
    public function run(int $farmId, array $periods, string $teamsFile, array $newOperations): array
    {
        $this->report = ['deleted' => [], 'created_employees' => [], 'unmatched' => [], 'files' => [], 'equipes' => [], 'differences' => [], 'warnings' => []];

        if (DB::table('manual_stock_entries')->whereNotNull('pointage_record_id')->exists()) {
            throw new RuntimeException('Des sorties de stock sont liées à des pointages : suppression refusée.');
        }

        $this->deletePointage($farmId);
        $this->loadReferences($farmId, $newOperations);

        // Division fiches first (they create the workers the team sheets refer to), then the
        // équipes and the vehicle each worked day was ridden on, then the presence-only periods.
        $quinzaines = [];
        foreach ($periods as $rank => $period) {
            $quinzaines[$rank] = $this->openQuinzaines($farmId, $period);
            foreach ($period['fiches'] as [$path, $enterpriseId]) {
                $this->importFiche($path, $quinzaines[$rank][$enterpriseId], Carbon::parse($period['start']), $rank);
            }
        }
        $this->applyLatestEmployeeData();
        $this->buildEquipes($farmId, $teamsFile, collect($periods)->pluck('presence')->filter()->all());
        $this->stampVehicles($farmId);
        foreach ($periods as $rank => $period) {
            if ($period['presence'] ?? null) {
                $this->importPresence($period['presence'], $quinzaines[$rank], Carbon::parse($period['start']));
            }
        }

        return $this->report;
    }

    /** Same as migration 000047: each worked day rides the vehicle of the worker's équipe. */
    private function stampVehicles(int $farmId): void
    {
        foreach (Equipe::where('farm_id', $farmId)->whereNotNull('transport_vehicle_id')->get() as $equipe) {
            PointageRecord::whereIn('employee_id', Employee::where('equipe_id', $equipe->id)->select('id'))
                ->update(['transport_vehicle_id' => $equipe->transport_vehicle_id]);
        }
    }

    private function deletePointage(int $farmId): void
    {
        $quinzaineIds = Quinzaine::whereHas('enterprise', fn ($q) => $q->where('farm_id', $farmId))->pluck('id');
        $vehicleIds = TransportVehicle::where('farm_id', $farmId)->pluck('id');

        $this->report['deleted'] = [
            'transport_vehicle_absences' => DB::table('transport_vehicle_absences')->whereIn('transport_vehicle_id', $vehicleIds)->delete(),
            'transport_snapshots' => DB::table('transport_snapshots')->whereIn('quinzaine_id', $quinzaineIds)->delete(),
            'quinzaine_summaries' => DB::table('quinzaine_summaries')->whereIn('quinzaine_id', $quinzaineIds)->delete(),
            'pointage_records' => PointageRecord::whereIn('quinzaine_id', $quinzaineIds)->delete(),
            'quinzaines' => Quinzaine::whereIn('id', $quinzaineIds)->delete(),
            'equipes' => tap(Equipe::where('farm_id', $farmId)->count(), function () use ($farmId) {
                Employee::where('farm_id', $farmId)->update(['equipe_id' => null]);
                Equipe::where('farm_id', $farmId)->delete();
            }),
        ];
    }

    private function loadReferences(int $farmId, array $newOperations): void
    {
        foreach ($newOperations as $abbreviation => $name) {
            if (! Operation::where('farm_id', $farmId)->whereRaw('UPPER(abbreviation) = ?', [mb_strtoupper($abbreviation)])->exists()) {
                Operation::create(['farm_id' => $farmId, 'name' => $name, 'abbreviation' => $abbreviation]);
                $this->report['warnings'][] = "Opération créée : {$abbreviation} ({$name})";
            }
        }
        foreach (Operation::where('farm_id', $farmId)->get() as $operation) {
            $this->operations[mb_strtoupper(trim($operation->abbreviation ?: $operation->name))] ??= $operation->id;
        }
        foreach (Bloc::where('farm_id', $farmId)->get() as $bloc) {
            $this->blocs[mb_strtoupper(trim($bloc->name))] = $bloc->id;
        }
        foreach (Employee::where('farm_id', $farmId)->get() as $employee) {
            $this->remember($employee);
        }
        $this->nextMatricule = (int) Employee::where('farm_id', $farmId)->max(DB::raw('CAST(matricule AS UNSIGNED)')) + 1;
    }

    private function remember(Employee $employee): void
    {
        if ($cin = $this->cinKey($employee->cin)) {
            $this->employeesByCin[$cin] = $employee;
        }
        $name = $employee->last_name || $employee->first_name ? $employee->last_name.' '.$employee->first_name : $employee->full_name;
        $this->employeesByName[$this->nameKey($name)] ??= $employee;
    }

    private function find(?string $cin, string $nom, string $prenom): ?Employee
    {
        return ($this->cinKey($cin) ? ($this->employeesByCin[$this->cinKey($cin)] ?? null) : null)
            ?? $this->employeesByName[$this->nameKey("{$nom} {$prenom}")]
            ?? null;
    }

    private function cinKey(?string $cin): ?string
    {
        $key = preg_replace('/\s+/', '', mb_strtoupper((string) $cin));

        return $key !== '' ? $key : null;
    }

    private function nameKey(string $name): string
    {
        return preg_replace('/[^A-Z]/', '', strtr(mb_strtoupper($name), ['É' => 'E', 'È' => 'E', 'Ê' => 'E', 'À' => 'A', 'Ï' => 'I', 'Î' => 'I', 'Ô' => 'O', 'Ç' => 'C']));
    }

    /** One open quinzaine per division of the farm for the period, keyed by enterprise id. */
    private function openQuinzaines(int $farmId, array $period): array
    {
        $quinzaines = [];
        foreach (Enterprise::where('farm_id', $farmId)->get() as $enterprise) {
            $quinzaines[$enterprise->id] = Quinzaine::create([
                'enterprise_id' => $enterprise->id, 'label' => $period['label'],
                'start_date' => $period['start'], 'end_date' => $period['end'], 'is_closed' => false,
            ])->setRelation('enterprise', $enterprise);
        }

        return $quinzaines;
    }

    private function importFiche(string $path, Quinzaine $quinzaine, Carbon $start, int $rank): void
    {
        $ws = IOFactory::load($path)->getSheet(0);
        $headerRow = $this->headerRow($ws);
        $columns = $this->columns($ws, $headerRow);
        $file = basename($path);
        if (! isset($columns['NET_J'])) {
            throw new RuntimeException("SAL NET/J introuvable dans {$file}");
        }
        $stats = ['file' => $file, 'quinzaine' => $quinzaine->label, 'division' => $quinzaine->enterprise->name, 'workers' => 0, 'days' => 0, 'net_file' => 0.0, 'net_imported' => 0.0];
        $seen = [];

        for ($r = $headerRow + 1; $r <= $ws->getHighestRow(); $r++) {
            $nom = $this->str($ws->getCell([2, $r])->getValue());
            if ($nom === '') {
                continue;
            }
            if (str_starts_with(mb_strtoupper($nom), 'TOTAL')) {
                break;
            }
            $prenom = $this->str($ws->getCell([3, $r])->getValue());
            $cin = $this->str($ws->getCell([4, $r])->getValue());
            $rib = isset($columns['RIB']) ? preg_replace('/\s+/', '', $this->str($ws->getCell([$columns['RIB'], $r])->getValue())) : '';
            $netJ = round((float) $this->num($ws, $columns['NET_J'], $r), 2);
            $hs = (float) $this->num($ws, $columns['HS'] ?? null, $r);
            $complement = round($netJ - round(self::BRUT * (1 - PayrollService::DEDUCTION_RATE), 2), 2);

            $employee = $this->find($cin, $nom, $prenom);
            // Two lines of one fiche are two people, even when a name-only match says otherwise.
            if (! $employee || isset($seen[$employee->id])) {
                $employee = $this->createEmployee($quinzaine->enterprise, $nom, $prenom, $cin, $rib, $complement);
            }
            $seen[$employee->id] = true;
            if (($this->latest[$employee->id][0] ?? -1) <= $rank) {
                $this->latest[$employee->id] = [$rank, ['enterprise_id' => $quinzaine->enterprise_id, 'complement' => $complement] + ($rib !== '' ? ['rib' => $rib] : [])];
            }

            $days = [];
            foreach ($columns['DAYS'] as $col => $day) {
                $code = mb_strtoupper($this->str($ws->getCell([$col, $r])->getValue()));
                if ($code !== '') {
                    $days[$start->copy()->setDay($day)->toDateString()] = $code;
                }
            }

            $hours = $this->spread($hs, count($days));
            $i = 0;
            $imported = 0.0;
            foreach ($days as $date => $code) {
                [$blocId, $operationId] = $this->resolveCode($code, $file, "{$nom} {$prenom}");
                $net = $this->writeRecord($employee, $quinzaine, $date, $operationId, $blocId, $hours[$i++] ?? 0, $complement);
                if ($net !== null) {
                    $imported += $net;
                    $stats['days']++;
                }
            }

            $stats['workers']++;
            $fileNet = isset($columns['TOTAL_NET']) ? $this->num($ws, $columns['TOTAL_NET'], $r) : count($days) * $netJ + $hs * 11.36;
            $stats['net_file'] += $fileNet;
            $stats['net_imported'] += $imported;
            // A TOTAL NET typed by hand (a fixed salary, a deduction) has no days to carry it.
            if (abs($fileNet - $imported) >= 1) {
                $this->report['differences'][] = sprintf('%s — %s %s (%s) : %d j. × %s, fichier %s DH, importé %s DH',
                    $file, $nom, $prenom, $quinzaine->label, count($days), $netJ, number_format($fileNet, 2, '.', ' '), number_format($imported, 2, '.', ' '));
            }
        }

        $stats['net_file'] = round($stats['net_file'], 2);
        $stats['net_imported'] = round($stats['net_imported'], 2);
        $this->report['files'][] = $stats;
    }

    /** Present with the operation "à préciser", from a team sheet's 1 marks. */
    private function importPresence(string $path, array $quinzaines, Carbon $start): void
    {
        $book = IOFactory::load($path);
        $stats = ['file' => basename($path), 'quinzaine' => reset($quinzaines)->label, 'division' => 'toutes (présence)', 'workers' => 0, 'days' => 0, 'net_file' => null, 'net_imported' => 0.0];

        foreach ($book->getWorksheetIterator() as $ws) {
            if (! $this->isTeamSheet($ws)) {
                continue;
            }
            $days = $this->columns($ws, 4)['DAYS'];
            for ($r = 5; $r <= $ws->getHighestRow(); $r++) {
                $nom = $this->str($ws->getCell([2, $r])->getValue());
                if ($nom === '' || str_starts_with(mb_strtoupper($nom), 'TOTAL') || str_starts_with(mb_strtoupper($nom), 'NOMBRE')) {
                    if (str_starts_with(mb_strtoupper($nom), 'TOTAL')) {
                        break;
                    }

                    continue;
                }
                $marked = [];
                foreach ($days as $col => $day) {
                    if ((string) $ws->getCell([$col, $r])->getValue() === '1') {
                        $marked[] = $start->copy()->setDay($day)->toDateString();
                    }
                }
                if (! $marked) {
                    continue;
                }
                $prenom = $this->str($ws->getCell([3, $r])->getValue());
                $cin = $this->str($ws->getCell([4, $r])->getValue());
                $employee = $this->find($cin, $nom, $prenom);
                if (! $employee) {
                    // New in this period: created in the division most of their équipe is in.
                    $division = $quinzaines[$this->teamDivision($ws->getTitle())]->enterprise;
                    $rib = preg_replace('/\s+/', '', $this->str($this->cellUnder($ws, 'RIB', $r)));
                    $employee = $this->createEmployee($division, $nom, $prenom, $cin, $rib, round(100 - round(self::BRUT * (1 - PayrollService::DEDUCTION_RATE), 2), 2));
                    $this->latest[$employee->id] = [PHP_INT_MAX, ['enterprise_id' => $division->id, 'complement' => (float) $employee->complement]];
                    $this->report['unmatched'][] = "{$ws->getTitle()} : {$nom} {$prenom} — nouvel ouvrier créé dans {$division->name} (NET/J 100), ".count($marked).' j.';
                }
                $enterpriseId = $this->latest[$employee->id][1]['enterprise_id'] ?? $employee->enterprise_id;
                $complement = $this->latest[$employee->id][1]['complement'] ?? (float) $employee->complement;
                foreach ($marked as $date) {
                    $net = $this->writeRecord($employee, $quinzaines[$enterpriseId], $date, null, null, 0, $complement);
                    if ($net !== null) {
                        $stats['net_imported'] += $net;
                        $stats['days']++;
                    }
                }
                $stats['workers']++;
            }
        }

        $stats['net_imported'] = round($stats['net_imported'], 2);
        $this->report['files'][] = $stats;
    }

    /** The division most members of the équipe named $title are in. */
    private function teamDivision(string $title): int
    {
        return Employee::whereHas('equipe', fn ($q) => $q->where('name', $title))
            ->selectRaw('enterprise_id, COUNT(*) as n')->groupBy('enterprise_id')->orderByDesc('n')->value('enterprise_id')
            ?? Employee::selectRaw('enterprise_id, COUNT(*) as n')->groupBy('enterprise_id')->orderByDesc('n')->value('enterprise_id');
    }

    /** The value in the column headed $label (row 4) of a team sheet. */
    private function cellUnder(Worksheet $ws, string $label, int $row): string
    {
        $highest = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ws->getHighestColumn());
        for ($c = 1; $c <= $highest; $c++) {
            if (mb_strtoupper($this->str($ws->getCell([$c, 4])->getValue())) === $label) {
                return $this->str($ws->getCell([$c, $row])->getValue());
            }
        }

        return '';
    }

    /** The day's net, or null when the worker already has that day (listed twice). */
    private function writeRecord(Employee $employee, Quinzaine $quinzaine, string $date, ?int $operationId, ?int $blocId, float $hours, float $complement): ?float
    {
        if (PointageRecord::where('employee_id', $employee->id)->where('quinzaine_id', $quinzaine->id)->whereDate('date', $date)->exists()) {
            $this->report['warnings'][] = "Doublon ignoré : {$employee->full_name} le {$date} ({$quinzaine->enterprise->name})";

            return null;
        }

        $enterprise = $quinzaine->enterprise;
        $calc = $this->payroll->calculate($enterprise->contract_type, self::BRUT, $hours, $complement, false, $enterprise->invoiced_to_client);
        PointageRecord::create([
            'employee_id' => $employee->id, 'quinzaine_id' => $quinzaine->id, 'date' => $date, 'present' => true,
            'operation_id' => $operationId, 'bloc_id' => $blocId, 'hours' => $hours, 'quantity' => null, 'is_jf' => false,
            'rate' => self::BRUT, 'brut' => $calc['brut'], 'net' => round($calc['total_net'], 2),
            'transport_vehicle_id' => $this->transport->riderVehicleId($employee->id),
        ]);

        return round($calc['total_net'], 2);
    }

    /** The period's H.S. total spread over the days worked, in half hours, the remainder on the last days. */
    private function spread(float $total, int $days): array
    {
        if ($days === 0 || $total <= 0) {
            return [];
        }
        $base = floor($total / $days * 2) / 2;
        $hours = array_fill(0, $days, $base);
        $left = round($total - $base * $days, 2);
        for ($i = $days - 1; $left > 0 && $i >= 0; $i--) {
            $add = min(0.5, $left);
            $hours[$i] += $add;
            $left = round($left - $add, 2);
        }
        $hours[$days - 1] += $left;

        return $hours;
    }

    private function resolveCode(string $code, string $file, string $who): array
    {
        if (in_array($code, self::OPERATIONS_WITHOUT_BLOC, true)) {
            return [null, $this->operations[$code] ?? throw new RuntimeException("Opération inconnue {$code} ({$file})")];
        }
        [$bloc, $abbreviation] = array_pad(explode('|', $code, 2), 2, null);
        $blocId = $this->blocs[trim($bloc)] ?? null;
        $operationId = $this->operations[trim((string) $abbreviation)] ?? null;
        if (! $blocId || ! $operationId) {
            throw new RuntimeException("Code inconnu « {$code} » pour {$who} dans {$file}");
        }

        return [$blocId, $operationId];
    }

    private function createEmployee(Enterprise $enterprise, string $nom, string $prenom, string $cin, string $rib, float $complement): Employee
    {
        $employee = Employee::create([
            'farm_id' => $enterprise->farm_id, 'enterprise_id' => $enterprise->id,
            'matricule' => str_pad((string) $this->nextMatricule++, 3, '0', STR_PAD_LEFT),
            'full_name' => trim("{$nom} {$prenom}"), 'last_name' => $nom, 'first_name' => $prenom,
            'cin' => $cin !== '' ? $cin : null, 'rib' => $rib !== '' ? $rib : null,
            'complement' => $complement, 'base_rate' => self::BRUT, 'is_active' => true,
        ]);
        $this->remember($employee);
        $this->report['created_employees'][] = "{$employee->matricule} {$employee->full_name} ({$enterprise->name})";

        return $employee;
    }

    private function applyLatestEmployeeData(): void
    {
        foreach ($this->latest as $employeeId => [, $attributes]) {
            Employee::whereKey($employeeId)->update($attributes + ['is_active' => true]);
        }
    }

    /**
     * Équipes from the transport workbook: one per chef d'équipe sheet, named like it, its chef
     * from "Chef d'équipe : NAME", riding the vehicle whose code has its name (else its number).
     * Workers who only appear in a later presence workbook join the same-named équipe.
     */
    private function buildEquipes(int $farmId, string $teamsFile, array $laterFiles): void
    {
        $vehicles = TransportVehicle::where('farm_id', $farmId)->get();
        $equipes = [];
        foreach (array_merge([$teamsFile], $laterFiles) as $index => $path) {
            foreach (IOFactory::load($path)->getWorksheetIterator() as $ws) {
                if (! $this->isTeamSheet($ws)) {
                    continue;
                }
                $title = $ws->getTitle();
                if (! isset($equipes[$title])) {
                    if ($index > 0) {
                        continue;
                    }
                    $equipes[$title] = Equipe::create([
                        'farm_id' => $farmId, 'name' => $title, 'is_active' => true,
                        'transport_vehicle_id' => $this->vehicleFor($title, $vehicles)?->id,
                    ]);
                    $this->report['equipes'][$title] = ['vehicle' => $equipes[$title]->transport_vehicle_id ? $vehicles->find($equipes[$title]->transport_vehicle_id)->code : null, 'chef' => null, 'members' => 0];
                }
                $equipe = $equipes[$title];

                if ($index === 0 && preg_match("/Chef d'équipe\s*:\s*(.+?)\s*(\||$)/u", (string) $ws->getCell('A2')->getValue(), $m)) {
                    $chef = $this->employeesByName[$this->nameKey($m[1])] ?? null;
                    if ($chef) {
                        $equipe->update(['chef_employee_id' => $chef->id]);
                        $this->report['equipes'][$title]['chef'] = $chef->full_name;
                    } else {
                        $this->report['warnings'][] = "Chef introuvable pour {$title} : {$m[1]}";
                    }
                }

                for ($r = 5; $r <= $ws->getHighestRow(); $r++) {
                    $nom = $this->str($ws->getCell([2, $r])->getValue());
                    if ($nom === '' || str_starts_with(mb_strtoupper($nom), 'TOTAL') || str_starts_with(mb_strtoupper($nom), 'NOMBRE')) {
                        if (str_starts_with(mb_strtoupper($nom), 'TOTAL')) {
                            break;
                        }

                        continue;
                    }
                    $prenom = $this->str($ws->getCell([3, $r])->getValue());
                    $employee = $this->find($this->str($ws->getCell([4, $r])->getValue()), $nom, $prenom);
                    if (! $employee) {
                        if ($index === 0) {
                            $this->report['unmatched'][] = "{$title} : {$nom} {$prenom} (équipe)";
                        }

                        continue;
                    }
                    if ($index === 0 || ! $employee->equipe_id) {
                        $employee->equipe_id = $equipe->id;
                        $employee->save();
                        $this->report['equipes'][$title]['members']++;
                    }
                }
            }
        }
    }

    private function vehicleFor(string $title, $vehicles): ?TransportVehicle
    {
        $words = preg_split('/\s+/', mb_strtoupper($title));
        $keyword = end($words);
        $byName = $vehicles->first(fn ($v) => str_contains(mb_strtoupper($v->code), $keyword));
        if ($byName) {
            return $byName;
        }
        if (preg_match('/^T(\d+)\b/i', $title, $m)) {
            return $vehicles->first(fn ($v) => preg_match('/^TRANS\s*'.$m[1].'\b/i', $v->code));
        }

        return null;
    }

    private function isTeamSheet(Worksheet $ws): bool
    {
        $title = mb_strtoupper($ws->getTitle());

        return ! in_array($title, ['RÉSUMÉ', 'RESUME', 'NOTES POINTAGE'], true) && ! str_starts_with($title, 'Q2 ')
            && mb_strtoupper($this->str($ws->getCell('B4')->getValue())) === 'NOM';
    }

    private function headerRow(Worksheet $ws): int
    {
        for ($r = 1; $r <= 10; $r++) {
            if (mb_strtoupper($this->str($ws->getCell([2, $r])->getValue())) === 'NOM') {
                return $r;
            }
        }
        throw new RuntimeException('En-tête introuvable dans '.$ws->getTitle());
    }

    /** Header label → column: NET_J, HS, RIB and DAYS (column => day of month). */
    private function columns(Worksheet $ws, int $row): array
    {
        $columns = ['DAYS' => []];
        $highest = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ws->getHighestColumn());
        for ($c = 1; $c <= $highest; $c++) {
            $label = preg_replace('/\s+/', ' ', mb_strtoupper($this->str($ws->getCell([$c, $row])->getValue())));
            if (preg_match('/^\d{1,2}$/', $label)) {
                $columns['DAYS'][$c] = (int) $label;
            } elseif (in_array($label, ['SAL NET / J', 'SAL NET/J'], true)) {
                $columns['NET_J'] = $c;
            } elseif ($label === 'H.S') {
                $columns['HS'] ??= $c;
            } elseif ($label === 'RIB') {
                $columns['RIB'] = $c;
            } elseif (in_array($label, ['TOTAL NET', 'TOTALNET'], true)) {
                $columns['TOTAL_NET'] ??= $c;
            }
        }

        return $columns;
    }

    private function num(Worksheet $ws, ?int $col, int $row): float
    {
        if (! $col) {
            return 0;
        }
        $value = $ws->getCell([$col, $row])->getCalculatedValue();

        return is_numeric($value) ? (float) $value : 0;
    }

    private function str($value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }
}
