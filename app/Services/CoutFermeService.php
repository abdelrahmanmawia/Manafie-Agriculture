<?php

namespace App\Services;

use App\Exports\PointageExport;
use App\Models\Employee;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\QuinzaineSummary;
use App\Models\TransportSnapshot;
use Illuminate\Support\Collection;

/**
 * What a period costs the farm, which isn't what the workers are paid: an invoiced division
 * (AGRI INTERIM) costs the interim company's invoice, its TTC; a non-declared one its net + the
 * 4% margin; a declared direct one the net it pays its workers — each the LA SOMME of its fiche
 * (PointageExport), a closed one's invoice being the TTC frozen in its snapshot — plus the period's transport (TransportService) with the transporter's 10%,
 * the sheet's TOTAL NET FACTURE, farm-wide for the dates: frozen once every division has closed
 * (transport_snapshots, which hold the net), live before — plus the charges of the companies under
 * contract (ChargeExterneService), farm-wide too. They aren't labour: total_hors_externes is the
 * cost without them, which the Analyses' figures against production keep to.
 */
class CoutFermeService
{
    public const MARGE_NON_DECLAREE = 0.04;

    public function __construct(private PayrollService $payroll, private TransportService $transport, private ChargeExterneService $externes) {}

    /**
     * Per quinzaine id: net (what its records pay), ttc (invoiced divisions only, else null),
     * marge (non-declared only), cout (what the farm pays for it) and fiche (PointageExport::FICHE_*).
     *
     * @param  Collection<int, Quinzaine>  $quinzaines  with their enterprise
     */
    public function parQuinzaine(Collection $quinzaines): array
    {
        $nets = PointageRecord::whereIn('quinzaine_id', $quinzaines->pluck('id'))
            ->groupBy('quinzaine_id')->selectRaw('quinzaine_id, SUM(net) as net')
            ->pluck('net', 'quinzaine_id');
        // What a closed quinzaine invoiced was frozen with it: it no longer depends on anyone's
        // complement of today, and needs no reading of its records.
        $figes = QuinzaineSummary::whereIn('quinzaine_id', $quinzaines->where('is_closed', true)->pluck('id'))
            ->pluck('total_ttc', 'quinzaine_id');

        $result = [];
        foreach ($quinzaines as $q) {
            $fiche = PointageExport::ficheFor($q->enterprise);
            $net = round((float) ($nets[$q->id] ?? 0), 2);
            $ttc = $fiche === PointageExport::FICHE_FACTURE ? (isset($figes[$q->id]) ? round((float) $figes[$q->id], 2) : $this->ttc($q)) : null;
            $marge = $fiche === PointageExport::FICHE_NON_DECLAREE ? round($net * self::MARGE_NON_DECLAREE, 2) : 0.0;
            $result[$q->id] = [
                'fiche' => $fiche,
                'net' => $net,
                'ttc' => $ttc,
                'marge' => $marge,
                'cout' => $ttc ?? round($net + $marge, 2),
            ];
        }

        return $result;
    }

    /**
     * Per period (key "Y-m-d_Y-m-d", its dates): the divisions' net, TTC and margin added up, the
     * transport, the external charges, and the total the farm pays.
     *
     * @param  Collection<int, Quinzaine>  $quinzaines  with their enterprise (a farm's)
     */
    public function parPeriode(Collection $quinzaines, int $farmId): array
    {
        $parQuinzaine = $this->parQuinzaine($quinzaines);
        $periodes = [];
        $groupes = $quinzaines->groupBy(fn ($q) => $q->start_date->toDateString().'_'.$q->end_date->toDateString());
        $externes = $this->externes->parPeriode($farmId, $groupes->keys()->all());
        foreach ($quinzaines->groupBy(fn ($q) => $q->start_date->toDateString().'_'.$q->end_date->toDateString()) as $key => $siblings) {
            $lignes = collect($siblings)->map(fn ($q) => $parQuinzaine[$q->id]);
            $transport = $this->transport($siblings, $farmId);
            $main = round($lignes->sum('cout'), 2);
            $periodes[$key] = [
                'net' => round($lignes->sum('net'), 2),
                'ttc' => round($lignes->sum(fn ($l) => $l['ttc'] ?? 0), 2),
                'net_direct' => round($lignes->filter(fn ($l) => $l['ttc'] === null)->sum('net'), 2),
                'marge' => round($lignes->sum('marge'), 2),
                'main_oeuvre' => $main,
                'transport' => $transport,
                'externes' => $externes[$key]['total'],
                'externes_detail' => $externes[$key]['detail'],
                'externes_manquantes' => $externes[$key]['manquantes'],
                'total_hors_externes' => round($main + $transport, 2),
                'total' => round($main + $transport + $externes[$key]['total'], 2),
            ];
        }

        return $periodes;
    }

    /** The invoice of an invoiced division's quinzaine: each worker's TOTAL TTC, as on the fiche. */
    private function ttc(Quinzaine $quinzaine): float
    {
        $records = PointageRecord::where('quinzaine_id', $quinzaine->id)->get()->groupBy('employee_id');
        $employees = Employee::whereIn('id', $records->keys())->get()->keyBy('id');
        $total = 0.0;
        foreach ($records as $employeeId => $employeeRecords) {
            $employee = $employees[$employeeId] ?? null;
            // A worker of another site isn't pointed: the month's salary is what's invoiced.
            if ($employee?->site_separe !== null && $employeeRecords->contains('nature', PointageRecord::NATURE_SALAIRE)) {
                $total += $this->payroll->invoicingSiteSepare($employee)['total_ttc'];

                continue;
            }
            $total += $this->payroll->invoicingForRecords(
                $quinzaine->enterprise->contract_type, $employeeRecords, (float) ($employee?->complement ?? 0),
                $quinzaine->enterprise->invoiced_to_client, $quinzaine->start_date
            )['total_ttc'];
        }

        return round($total, 2);
    }

    /** The period's invoiced transport: frozen when all its divisions are closed, live before. */
    private function transport(Collection $siblings, int $farmId): float
    {
        $first = $siblings->first();
        if ($siblings->every(fn ($q) => $q->is_closed)) {
            $frozen = TransportSnapshot::whereIn('quinzaine_id', $siblings->pluck('id'));
            if ($frozen->exists()) {
                return round((float) $frozen->sum('subtotal') * TransportService::FACTURATION, 2);
            }
        }

        $net = (float) collect($this->transport->previewForDateRange($first->start_date, $first->end_date, $farmId))->sum('subtotal');

        return round($net * TransportService::FACTURATION, 2);
    }
}
