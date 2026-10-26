<?php

namespace App\Services;

use App\Exports\PointageExport;
use App\Models\Equipe;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use Carbon\CarbonPeriod;

/**
 * One period's workers grouped the way the farm pays them — by équipe (its transport), chef first
 * — across every division: shared by "Paiement par équipe" (PaiementEquipesExport) and the
 * "Fiche des jours" (FicheJoursExport), so both list the same people the same way.
 */
class EquipePaieService
{
    public function __construct(private PayrollService $payroll) {}

    /**
     * [$days, $groups] for the reference quinzaine's dates: every division's quinzaine for those
     * dates (only $enterpriseId's for a division-locked user). Each group: name, chef, vehicle,
     * rows (chef first, then by name). "Sans équipe" comes last. Workers paid nothing are left out
     * unless $avecNonPayes (the fiche des jours lists a salaried worker's days too).
     */
    public function groupes(Quinzaine $reference, ?int $enterpriseId = null, int|string|null $equipe = null, bool $avecNonPayes = false): array
    {
        $farmId = $reference->enterprise->farm_id;
        $days = collect(CarbonPeriod::create($reference->start_date, $reference->end_date))->map(fn ($d) => $d->format('Y-m-d'))->all();

        $quinzaineIds = Quinzaine::whereHas('enterprise', fn ($q) => $q->where('farm_id', $farmId))
            ->when($enterpriseId, fn ($q) => $q->where('enterprise_id', $enterpriseId))
            ->whereDate('start_date', $reference->start_date)
            ->whereDate('end_date', $reference->end_date)
            ->pluck('id');

        $records = PointageRecord::whereIn('quinzaine_id', $quinzaineIds)->with('employee.enterprise')->get();

        $rows = [];
        foreach ($records->groupBy('employee_id') as $employeeRecords) {
            $employee = $employeeRecords->first()->employee;
            $enterprise = $employee->enterprise;
            $jours = $employeeRecords->where('nature', PointageRecord::NATURE_JOUR);
            $present = $employeeRecords->where('present', true);
            $net = round((float) $employeeRecords->sum('net'), 2);
            $presentDays = $present->map(fn ($r) => $r->date->format('Y-m-d'))->filter(fn ($d) => in_array($d, $days, true))->unique()->values()->all();
            if ($net <= 0 && ! ($avecNonPayes && ($presentDays || $jours->where('is_jf', true)->isNotEmpty()))) {
                continue;
            }

            // A salaried worker's days pay nothing (PointageRecordObserver): no day rate to show.
            $netJ = $employee->salaire_mensuel !== null ? 0.0
                : $this->payroll->salNetJFromRecords($enterprise->contract_type, $present, $employee->complement, $enterprise->default_brut_rate);
            // A fixed monthly salary (SalaireFixeService) gets its own column, not PRIMES.
            $salaireFixe = round((float) $employeeRecords->where('nature', PointageRecord::NATURE_SALAIRE)->sum('net'), 2);

            [$nom, $prenom] = $employee->last_name || $employee->first_name
                ? [$employee->last_name ?? '', $employee->first_name ?? '']
                : array_pad(explode(' ', $employee->full_name, 2), 2, '');
            $rib = (string) $employee->rib;

            $rows[] = [
                'employee_id' => $employee->id,
                'equipe_id' => $employee->equipe_id,
                'nom' => $nom,
                'prenom' => $prenom,
                'cin' => (string) $employee->cin,
                'division' => $enterprise->name,
                'days' => $presentDays,
                'hs' => round((float) $jours->sum('hours'), 2),
                'jf' => $jours->where('is_jf', true)->count(),
                'net_j' => $netJ,
                'primes' => round($net - count($presentDays) * $netJ - $salaireFixe, 2),
                'salaire_fixe' => $salaireFixe,
                'rib' => $rib,
                'paiement' => self::paiement($rib),
                'couverture' => $this->couverture($enterprise, $rib),
                'a_preciser' => $present->whereNull('operation_id')->count(),
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

        if ($equipe !== null) {
            $groups = $groups->only([$equipe === 'none' ? 0 : (int) $equipe]);
        }

        // By name, "Sans équipe" last.
        $sansEquipe = $groups->pull(0);
        $groups = $groups->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        if ($sansEquipe) {
            $groups->push($sansEquipe);
        }

        return [$days, $groups->all()];
    }

    /**
     * Days worked in the period per fiche (AGRI INTERIM / declared / non-declared), counted from
     * the records like each division's fiche counts its TOTAL J — the check under the RECAP.
     */
    public function joursParFiche(Quinzaine $reference, ?int $enterpriseId = null): array
    {
        $quinzaines = Quinzaine::with('enterprise')
            ->whereHas('enterprise', fn ($q) => $q->where('farm_id', $reference->enterprise->farm_id))
            ->when($enterpriseId, fn ($q) => $q->where('enterprise_id', $enterpriseId))
            ->whereDate('start_date', $reference->start_date)
            ->whereDate('end_date', $reference->end_date)
            ->get();

        $parFiche = [PointageExport::FICHE_FACTURE => 0, PointageExport::FICHE_NON_DECLAREE => 0, PointageExport::FICHE_DECLAREE => 0];
        foreach ($quinzaines as $quinzaine) {
            $parFiche[PointageExport::ficheFor($quinzaine->enterprise)] += PointageRecord::where('quinzaine_id', $quinzaine->id)
                ->where('nature', PointageRecord::NATURE_JOUR)->where('present', true)->count();
        }

        return $parFiche;
    }

    /** How a worker is paid, as the payment workbook's own formula decides it from the RIB. */
    public static function paiement(string $rib): string
    {
        if (trim($rib) === '') {
            return 'ESPÈCES';
        }

        return strlen(str_replace(' ', '', $rib)) === 24 ? 'VIREMENT' : 'RIB À VÉRIFIER';
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

    public static function periodLabel(Quinzaine $reference): string
    {
        $start = $reference->start_date;
        $end = $reference->end_date;
        $months = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

        return ($start->day <= 15 ? '1ère' : '2ème').' quinzaine '.$months[$start->month].' '.$start->year
            .' ('.$start->format('d').' → '.$end->format('d/m/Y').')';
    }

    /** An Excel sheet name: ≤ 31 chars, none of \ / ? * [ ] :, unique in the workbook. */
    public static function sheetTitle(string $name, array &$taken): string
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
