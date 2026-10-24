<?php

namespace App\Exports;

use App\Models\Equipe;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Services\PayrollService;
use Carbon\CarbonPeriod;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithPreCalculateFormulas;

/**
 * "Paiement par équipe" for one period: what each worker is paid, grouped the way the farm pays —
 * by chef d'équipe — across every division, laid out like the farm's own "POINTAGE PAR TRANSPORT"
 * workbook: a RÉSUMÉ sheet (per équipe: workers, days, net, by payment method; headcount per day)
 * and one sheet per équipe (+ "Sans équipe"). NET À PAYER = TOTAL JOURS × SAL. NET/J + PRIMES,
 * where PRIMES is everything else the records pay (H.S., J.F., piece-rate, a NET/J that changed)
 * so it always equals the records' stored net. How each worker is paid (PAIEMENT) follows the
 * workbook's own formula on the RIB; their coverage (AMO / CNSS) follows their division.
 */
class PaiementEquipesExport implements WithMultipleSheets, WithPreCalculateFormulas
{
    use Exportable;

    public function __construct(private Quinzaine $reference, private ?int $enterpriseId = null) {}

    public function sheets(): array
    {
        [$days, $groups] = $this->groups();
        $period = $this->periodLabel();

        $titles = [];
        $sheets = [];
        foreach ($groups as $group) {
            $title = $this->uniqueTitle($group['name'], $titles);
            $sheets[] = new PaiementEquipeSheet($title, $group, $days, $period);
        }

        return array_merge([new PaiementResumeSheet($sheets, $days, $period)], $sheets);
    }

    /**
     * The period's paid workers grouped by their équipe (chef first, then by name; "Sans équipe"
     * last), each with their days present and pay. Every division's quinzaine for the reference's
     * dates is included — only the user's own when they are locked to a division.
     */
    private function groups(): array
    {
        $farmId = $this->reference->enterprise->farm_id;
        $days = collect(CarbonPeriod::create($this->reference->start_date, $this->reference->end_date))->map(fn ($d) => $d->format('Y-m-d'))->all();

        $quinzaineIds = Quinzaine::whereHas('enterprise', fn ($q) => $q->where('farm_id', $farmId))
            ->when($this->enterpriseId, fn ($q) => $q->where('enterprise_id', $this->enterpriseId))
            ->whereDate('start_date', $this->reference->start_date)
            ->whereDate('end_date', $this->reference->end_date)
            ->pluck('id');

        $records = PointageRecord::whereIn('quinzaine_id', $quinzaineIds)->with('employee.enterprise')->get();
        $payroll = app(PayrollService::class);

        $rows = [];
        foreach ($records->groupBy('employee_id') as $employeeRecords) {
            $employee = $employeeRecords->first()->employee;
            $enterprise = $employee->enterprise;
            $present = $employeeRecords->where('present', true);
            $net = round((float) $employeeRecords->sum('net'), 2);
            if ($net <= 0) {
                continue;
            }

            // A salaried worker's days pay nothing (PointageRecordObserver): no day rate to show.
            $netJ = $employee->salaire_mensuel !== null ? 0.0
                : $payroll->salNetJFromRecords($enterprise->contract_type, $present, $employee->complement, $enterprise->default_brut_rate);
            $presentDays = $present->map(fn ($r) => $r->date->format('Y-m-d'))->filter(fn ($d) => in_array($d, $days, true))->unique()->values()->all();
            $aPreciser = $present->whereNull('operation_id')->count();
            // A fixed monthly salary (SalaireFixeService) gets its own column, not PRIMES.
            $salaireFixe = round((float) $employeeRecords->where('nature', PointageRecord::NATURE_SALAIRE)->sum('net'), 2);

            [$nom, $prenom] = $employee->last_name || $employee->first_name
                ? [$employee->last_name ?? '', $employee->first_name ?? '']
                : array_pad(explode(' ', $employee->full_name, 2), 2, '');

            $rows[] = [
                'employee_id' => $employee->id,
                'equipe_id' => $employee->equipe_id,
                'nom' => $nom,
                'prenom' => $prenom,
                'cin' => (string) $employee->cin,
                'division' => $enterprise->name,
                'days' => $presentDays,
                'net_j' => $netJ,
                'primes' => round($net - count($presentDays) * $netJ - $salaireFixe, 2),
                'salaire_fixe' => $salaireFixe,
                'rib' => (string) $employee->rib,
                'couverture' => $this->couverture($enterprise, (string) $employee->rib),
                'a_preciser' => $aPreciser,
            ];
        }

        $equipes = Equipe::with(['chef:id,full_name', 'transportVehicle:id,code'])
            ->whereIn('id', collect($rows)->pluck('equipe_id')->filter()->unique())
            ->get()->keyBy('id');

        $groups = collect($rows)->groupBy(fn ($row) => $row['equipe_id'] ?? 0)->map(function ($members, $equipeId) use ($equipes) {
            $equipe = $equipes->get($equipeId);
            $chefId = $equipe?->chef_employee_id;
            $members = $members
                ->sortBy(fn ($row) => [$row['employee_id'] === $chefId ? 0 : 1, $row['nom'].' '.$row['prenom']])
                ->map(fn ($row) => $row + ['chef' => $row['employee_id'] === $chefId])
                ->values()->all();

            return [
                'name' => $equipe?->name ?? 'Sans équipe',
                'chef' => $equipe?->chef?->full_name,
                'vehicle' => $equipe?->transportVehicle?->code,
                'rows' => $members,
            ];
        });

        // By name, "Sans équipe" last.
        $sansEquipe = $groups->pull(0);
        $groups = $groups->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        if ($sansEquipe) {
            $groups->push($sansEquipe);
        }
        $groups = $groups->all();

        return [$days, $groups];
    }

    /**
     * The worker's social coverage, from their division: an invoicing division's workers (the
     * prestataire's) are CNSS, a non-declared division's are AMO, and in the others whoever is
     * paid on a RIB is declared (CNSS), the rest AMO.
     */
    private function couverture($enterprise, string $rib): string
    {
        return match (PointageExport::ficheFor($enterprise)) {
            PointageExport::FICHE_FACTURE => 'CNSS',
            PointageExport::FICHE_NON_DECLAREE => 'AMO',
            default => trim($rib) !== '' ? 'CNSS' : 'AMO',
        };
    }

    private function periodLabel(): string
    {
        $start = $this->reference->start_date;
        $end = $this->reference->end_date;
        $months = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

        return ($start->day <= 15 ? '1ère' : '2ème').' quinzaine '.$months[$start->month].' '.$start->year
            .' ('.$start->format('d').' → '.$end->format('d/m/Y').')';
    }

    /** An Excel sheet name: ≤ 31 chars, none of \ / ? * [ ] :, unique in the workbook. */
    private function uniqueTitle(string $name, array &$taken): string
    {
        $base = mb_substr(trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name)), 0, 31);
        $title = $base;
        for ($i = 2; in_array(mb_strtolower($title), $taken, true); $i++) {
            $title = mb_substr($base, 0, 31 - strlen(" ({$i})"))." ({$i})";
        }
        $taken[] = mb_strtolower($title);

        return $title;
    }
}
