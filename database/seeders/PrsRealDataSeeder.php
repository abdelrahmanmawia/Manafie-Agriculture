<?php

namespace Database\Seeders;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\QuinzaineSummary;
use App\Services\PrsImport\OperationEstimator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports the real PRS payroll archive (see database/seeders/prs_manifest.php) to replace the
 * demo Employee/Quinzaine/PointageRecord data seeded by PointageSeeder. Farm/Bloc/Sector/
 * Parcelle/Operation reference data from CsvDataSeeder is real and kept as-is.
 *
 * Deliberately does NOT call PayrollService::generateSnapshot() — that recomputes every total
 * live from the enterprise's CURRENT default_brut_rate and each employee's CURRENT complement,
 * which would silently overwrite the historically-correct imported figures for any period where
 * the real rate differed from today's (confirmed: Jan-May 2026 used a different rate/formula
 * than June-July). QuinzaineSummary is built directly from each file's own real totals instead.
 */
class PrsRealDataSeeder extends Seeder
{
    // Flat-rate technician/admin rows mixed into otherwise-clean daily-rate worker rosters — not
    // "ouvrières" (field workers), confirmed with the user: OUBAKADIR YAHYA ("TECHNICIEN ASSILAH",
    // AGRI INTERIM 2QZ Juin, flat 3500/month) and MOAWIA HASSAN ABDERRAHAMANABDELRAHMAN (same
    // pattern, PERSEALAND NON DECLARE 2QZ Juin).
    private const EXCLUDED_CINS = ['UA107688', 'P06073502'];

    private array $blocCache = [];
    private array $operationCache = [];
    private ?int $fallbackOperationId = null;
    private ?int $fallbackBlocId = null;
    // Some quinzaines are split across several manifest entries (Baraka Green: one file per
    // operation for the same period) — total_ttc isn't stored per PointageRecord, so it has to
    // be accumulated across every entry touching that quinzaine, keyed by quinzaine_id.
    private array $quinzaineTtcTotals = [];
    // Employee is farm-scoped (CIN is the real identity), but manifest entries are processed
    // enterprise-block by enterprise-block, not in real chronological order — so "last entry
    // processed wins" would pick whichever enterprise's manifest block happens to come last,
    // not whichever period is actually most recent. Track each identity's latest quinzaine
    // end-date seen so far and only let a later-dated entry overwrite the "current division"
    // fields (enterprise_id, matricule, full_name, rate, ...).
    private array $employeeLatestDate = [];

    public function run(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF;');
        PointageRecord::truncate();
        QuinzaineSummary::truncate();
        Quinzaine::truncate();
        Employee::truncate();
        DB::statement('PRAGMA foreign_keys = ON;');

        $persealandFarm = Farm::firstOrCreate(['name' => 'Persealand']);

        $enterprises = [
            'PERSEALAND' => [$persealandFarm, ['contract_type' => 'avec_contrat', 'invoiced_to_client' => false, 'default_brut_rate' => 97.44]],
            'AGRI INTERIM' => [$persealandFarm, ['contract_type' => 'avec_contrat', 'invoiced_to_client' => true, 'default_brut_rate' => 97.44]],
            'PERSEALAND NON DECLARE' => [$persealandFarm, ['contract_type' => 'avec_contrat', 'invoiced_to_client' => false, 'default_brut_rate' => 97.44]],
        ];
        $enterpriseIds = [];
        $enterpriseFarmIds = [];
        foreach ($enterprises as $name => [$farm, $attrs]) {
            $enterpriseIds[$name] = Enterprise::updateOrCreate(
                ['farm_id' => $farm->id, 'name' => $name],
                $attrs + ['settings' => ['currency' => 'DH']]
            )->id;
            $enterpriseFarmIds[$name] = $farm->id;
        }

        $archiveDir = storage_path('app/prs_archive');
        $manifest = require __DIR__ . '/prs_manifest.php';

        foreach ($manifest as $entry) {
            $this->importEntry($entry, $archiveDir, $enterpriseFarmIds[$entry['enterprise']], $enterpriseIds);
        }

        $this->command->info('PrsRealDataSeeder complete: ' . Employee::count() . ' employees, '
            . PointageRecord::count() . ' pointage records, ' . Quinzaine::count() . ' quinzaines.');
    }

    private function importEntry(array $entry, string $archiveDir, int $farmId, array $enterpriseIds): void
    {
        $path = $archiveDir . DIRECTORY_SEPARATOR . $entry['file'];
        if (!file_exists($path)) {
            $this->command->warn("Missing file, skipping: {$entry['file']}");
            return;
        }

        if (isset($entry['memory_limit'])) {
            ini_set('memory_limit', $entry['memory_limit']);
        }

        $enterpriseId = $enterpriseIds[$entry['enterprise']];
        [$startDate, $endDate] = $this->periodDates($entry['year'], $entry['month'], $entry['period']);

        $reader = IOFactory::createReaderForFile($path);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($entry['sheet']);
        if (!$sheet) {
            $this->command->warn("Missing sheet '{$entry['sheet']}' in {$entry['file']}, skipping.");
            return;
        }

        // 'parser' is either a class-string (default constructor) or a closure factory, for
        // parsers like FixedOperationParser that need constructor arguments per manifest entry.
        $parser = $entry['parser'] instanceof \Closure ? ($entry['parser'])() : new $entry['parser']();
        $parsedEmployees = $parser->parse($sheet, $entry['year'], $entry['month']);
        $parsedEmployees = array_values(array_filter(
            $parsedEmployees,
            fn ($data) => !in_array(trim((string) ($data['cin'] ?? '')), self::EXCLUDED_CINS, true)
        ));
        (new OperationEstimator())->apply($sheet, $entry['year'], $entry['month'], $parsedEmployees);

        $quinzaine = Quinzaine::where('enterprise_id', $enterpriseId)
            ->whereDate('start_date', $startDate)
            ->whereDate('end_date', $endDate)
            ->first();
        if (!$quinzaine) {
            $quinzaine = Quinzaine::create([
                'enterprise_id' => $enterpriseId,
                'label' => $entry['period'] . 'QZ ' . $this->frenchMonth($entry['month']) . ' ' . $entry['year'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_closed' => true,
            ]);
        }

        foreach ($parsedEmployees as $data) {
            if (empty($data['days'])) {
                continue;
            }

            $employee = $this->resolveEmployee($entry, $farmId, $enterpriseId, $data, $endDate);

            $this->insertPointageRecords($employee, $quinzaine, $data, $farmId);

            $this->quinzaineTtcTotals[$quinzaine->id] = ($this->quinzaineTtcTotals[$quinzaine->id] ?? 0) + ($data['total_ttc'] ?? 0);
        }

        $this->buildSummary($quinzaine, $this->quinzaineTtcTotals[$quinzaine->id] ?? 0);
    }

    /**
     * Employee identity is CIN-first (farm-scoped): the same real worker keeps one row across
     * enterprises. Employees with no recorded CIN fall back to (enterprise, matricule) — the
     * best available identity when CIN is missing, at the cost of not merging that specific
     * person's rows across enterprises (matches the migration's own nullable-CIN tradeoff).
     */
    private function resolveEmployee(array $entry, int $farmId, int $enterpriseId, array $data, string $endDate): Employee
    {
        $cin = trim((string) ($data['cin'] ?? ''));
        $identity = $cin !== ''
            ? ['farm_id' => $farmId, 'cin' => $cin]
            : ['farm_id' => $farmId, 'enterprise_id' => $enterpriseId, 'matricule' => $data['matricule'], 'cin' => null];

        $key = $cin !== '' ? "cin:{$farmId}:{$cin}" : "nc:{$farmId}:{$enterpriseId}:{$data['matricule']}";
        $isLatest = !isset($this->employeeLatestDate[$key]) || $endDate >= $this->employeeLatestDate[$key];

        $attrs = [
            'enterprise_id' => $enterpriseId,
            'matricule' => $data['matricule'],
            'full_name' => $data['full_name'],
            'last_name' => $data['last_name'] ?? null,
            'first_name' => $data['first_name'] ?? null,
            'rib' => $data['rib'],
            'complement' => $data['complement'],
            'base_rate' => $this->resolveBrutRate($data),
            'type' => str_contains($entry['enterprise'], 'AGRI INTERIM') ? 'interim' : 'persea',
            'is_active' => true,
        ];

        if ($isLatest) {
            $this->employeeLatestDate[$key] = $endDate;
            return Employee::updateOrCreate($identity, $attrs);
        }

        return Employee::firstOrCreate($identity, $attrs);
    }

    /**
     * Sheets like "UNITE" have neither a per-day rate column nor total_net's usual siblings —
     * only a period TOTAL/TOTAL NET column — so fall back to that total spread across the
     * employee's worked days rather than leaving the rate at 0.
     */
    private function resolveBrutRate(array $data): float
    {
        if ($data['brut'] ?? null) {
            return $data['brut'];
        }
        if ($data['net_per_day'] ?? null) {
            return $data['net_per_day'];
        }
        $days = count($data['days'] ?? []);
        if ($days > 0 && ($data['total_net'] ?? 0) > 0) {
            return $data['total_net'] / $days;
        }

        return 0;
    }

    private function insertPointageRecords(Employee $employee, Quinzaine $quinzaine, array $data, int $farmId): void
    {
        $dates = array_keys($data['days']);
        if (empty($dates)) {
            return;
        }
        sort($dates);

        // Each date maps to a LIST of entries, not a single one — the same employee can
        // genuinely do piece-rate meterage in more than one Bloc on the same calendar day
        // (confirmed against the real source file). Piece-rate entries already carry their own
        // exact net and must NOT participate in the flat-rate "last day absorbs the delta"
        // distribution below, which assumes every plain day is worth the same net_per_day.
        $isPlainDate = fn ($d) => empty(array_filter($data['days'][$d], fn ($e) => isset($e['net_override'])));
        $plainDates = array_values(array_filter($dates, $isPlainDate));
        $lastPlainDate = !empty($plainDates) ? end($plainDates) : null;
        $totalPlainDays = count($plainDates);

        $brut = $this->resolveBrutRate($data);
        $perDayNet = $totalPlainDays > 0 ? ($data['net_per_day'] ?? (($data['total_net'] ?? 0) / $totalPlainDays)) : 0;
        $sumOfPlainDays = $perDayNet * $totalPlainDays;
        // The file gives period totals (hs/jf/total_net), not a per-day breakdown — absorb the
        // whole period's overtime/holiday/rounding delta into the last worked day so that the
        // sum of every day's stored net exactly matches this employee's real period total.
        $adjustment = $totalPlainDays > 0 ? (($data['total_net'] ?? $sumOfPlainDays) - $sumOfPlainDays) : 0;

        $rows = [];
        $adjustmentApplied = false;
        foreach ($dates as $date) {
            $isLastPlainDate = $date === $lastPlainDate;
            foreach ($data['days'][$date] as $dayInfo) {
                if (isset($dayInfo['net_override'])) {
                    $dayRate = $dayInfo['rate_override'] ?? $brut;
                    $rows[] = [
                        'employee_id' => $employee->id,
                        'quinzaine_id' => $quinzaine->id,
                        'operation_id' => $this->resolveOperation($dayInfo['operation'], $farmId),
                        'bloc_id' => $this->resolveBloc($dayInfo['bloc'], $farmId),
                        'date' => $date,
                        'hours' => 0,
                        'quantity' => $dayInfo['quantity'] ?? null,
                        'is_jf' => false,
                        'rate' => $dayRate,
                        'brut' => $dayRate,
                        'net' => $dayInfo['net_override'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    continue;
                }

                // The HS/JF-adjusted "last day" bonus is a once-per-employee-per-period amount —
                // apply it to only the first plain entry encountered on the last plain date, even
                // if that date happens to carry more than one plain entry.
                $applyAdjustment = $isLastPlainDate && !$adjustmentApplied;
                $adjustmentApplied = $adjustmentApplied || $applyAdjustment;

                $rows[] = [
                    'employee_id' => $employee->id,
                    'quinzaine_id' => $quinzaine->id,
                    'operation_id' => $this->resolveOperation($dayInfo['operation'], $farmId),
                    'bloc_id' => $this->resolveBloc($dayInfo['bloc'], $farmId),
                    'date' => $date,
                    'hours' => $applyAdjustment ? ($data['hs_total'] ?? 0) : 0,
                    'quantity' => null,
                    'is_jf' => $applyAdjustment && ($data['jf_count'] ?? 0) > 0,
                    'rate' => $brut,
                    'brut' => $brut,
                    'net' => $applyAdjustment ? $perDayNet + $adjustment : $perDayNet,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Deliberately NOT scoped to this quinzaine_id: Employee identity is now CIN-unified
        // across enterprises, so the same real worker's calendar dates can otherwise collide
        // across two different enterprises' quinzaines for what's really the same period (e.g.
        // Persealand and Baraka Green both recording the same person for the same days). Manifest
        // entries are listed in source-of-truth priority order (Persealand before Baraka Green,
        // etc.) so "first entry to claim a date wins" also resolves those cross-file duplicates,
        // on top of its original purpose: Baraka Green's own per-operation sheet split for one
        // quinzaine, which must not double-count the same employee/date twice either.
        $existingDates = PointageRecord::where('employee_id', $employee->id)
            ->pluck('date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->all();
        $rows = array_values(array_filter($rows, fn ($row) => !in_array($row['date'], $existingDates, true)));

        if (!empty($rows)) {
            PointageRecord::insert($rows);
        }
    }

    private function buildSummary(Quinzaine $quinzaine, float $totalTtc): void
    {
        $totals = PointageRecord::where('quinzaine_id', $quinzaine->id)
            ->selectRaw('COALESCE(SUM(net),0) as total_net, COALESCE(SUM(brut),0) as total_brut, COALESCE(SUM(hours),0) as total_hours')
            ->first();

        $employeeCount = PointageRecord::where('quinzaine_id', $quinzaine->id)->distinct('employee_id')->count('employee_id');

        $employeeNets = PointageRecord::where('quinzaine_id', $quinzaine->id)
            ->selectRaw('employee_id, SUM(net) as total_net')
            ->groupBy('employee_id')
            ->pluck('total_net', 'employee_id')
            ->toArray();

        $opCosts = PointageRecord::where('pointage_records.quinzaine_id', $quinzaine->id)
            ->join('operations', 'pointage_records.operation_id', '=', 'operations.id')
            ->selectRaw('operations.name, SUM(pointage_records.net) as total_net')
            ->groupBy('operations.name')
            ->orderByDesc('total_net')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'total_net' => (float) $r->total_net])
            ->toArray();

        $blocCosts = PointageRecord::where('pointage_records.quinzaine_id', $quinzaine->id)
            ->join('blocs', 'pointage_records.bloc_id', '=', 'blocs.id')
            ->selectRaw('blocs.name, SUM(pointage_records.net) as total_net')
            ->groupBy('blocs.name')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'total_net' => (float) $r->total_net])
            ->toArray();

        QuinzaineSummary::updateOrCreate(
            ['quinzaine_id' => $quinzaine->id],
            [
                'total_net' => $totals->total_net,
                'total_ttc' => $totalTtc,
                'total_brut' => $totals->total_brut,
                'total_hours' => $totals->total_hours,
                'employee_count' => $employeeCount,
                'summary_data' => [
                    'employee_nets' => $employeeNets,
                    'op_costs' => $opCosts,
                    'bloc_costs' => $blocCosts,
                ],
            ]
        );
    }

    private function resolveBloc(?string $name, int $farmId): int
    {
        if (!$name) {
            return $this->fallbackBlocId ??= Bloc::firstOrCreate(
                ['name' => 'Non spécifié', 'farm_id' => $farmId]
            )->id;
        }
        $key = $farmId . '|' . $name;
        return $this->blocCache[$key] ??= Bloc::firstOrCreate(['name' => $name, 'farm_id' => $farmId])->id;
    }

    private function resolveOperation(?string $abbreviation, int $farmId): int
    {
        if (!$abbreviation) {
            return $this->fallbackOperationId ??= Operation::firstOrCreate(
                ['abbreviation' => 'N/A', 'farm_id' => $farmId],
                ['name' => 'Non spécifié']
            )->id;
        }
        $key = $farmId . '|' . strtoupper($abbreviation);
        if (isset($this->operationCache[$key])) {
            return $this->operationCache[$key];
        }

        $existing = Operation::where('farm_id', $farmId)
            ->whereRaw('UPPER(abbreviation) = ?', [strtoupper($abbreviation)])
            ->first();
        $operation = $existing ?? Operation::create([
            'farm_id' => $farmId,
            'name' => $abbreviation,
            'abbreviation' => $abbreviation,
        ]);

        return $this->operationCache[$key] = $operation->id;
    }

    private function periodDates(int $year, int $month, string $period): array
    {
        $start = $period === '1' ? 1 : 16;
        $lastDay = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $end = $period === '1' ? 15 : $lastDay;

        return [
            sprintf('%04d-%02d-%02d', $year, $month, $start),
            sprintf('%04d-%02d-%02d', $year, $month, $end),
        ];
    }

    private function frenchMonth(int $month): string
    {
        $months = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai',
            6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre',
            11 => 'Novembre', 12 => 'Décembre'];
        return $months[$month];
    }
}
