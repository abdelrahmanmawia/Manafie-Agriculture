<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\QuinzaineSummary;
use Illuminate\Support\Facades\DB;

/**
 * The Historique de Paie table and its Excel export: each worker's net per pay period, from the
 * closing snapshot of a closed quinzaine (its numbers are frozen), the records of any other.
 */
class HistoriquePaieService
{
    private const MOIS = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    /** How many periods the table opens on. */
    public const PERIODES_PAR_DEFAUT = 6;

    public function calculer(int $farmId, ?int $enterpriseId): array
    {
        $quinzaines = Quinzaine::query()
            ->when($enterpriseId, fn ($q) => $q->where('enterprise_id', $enterpriseId))
            ->when(! $enterpriseId, fn ($q) => $q->whereHas('enterprise', fn ($eq) => $eq->where('farm_id', $farmId)))
            ->orderBy('start_date', 'desc')
            ->get();

        // Each division opens its own Quinzaine row for the same real-world pay period, so without
        // grouping the matrix would show one duplicate (mostly empty) column per division. Merge
        // quinzaines that share the same start/end date into a single period column.
        $periods = $quinzaines
            ->groupBy(fn ($q) => $q->start_date->format('Y-m-d').'_'.$q->end_date->format('Y-m-d'))
            ->map(function ($group) {
                $first = $group->first();

                return (object) [
                    'key' => $first->start_date->format('Y-m-d').'_'.$first->end_date->format('Y-m-d'),
                    'label' => ($first->start_date->day <= 15 ? '1' : '2').'QZ '.self::MOIS[$first->start_date->month].' '.$first->start_date->year,
                    'start_date' => $first->start_date,
                    'end_date' => $first->end_date,
                    'quinzaine_ids' => $group->pluck('id')->all(),
                    // Amounts of a period not closed yet can still change, and aren't paid yet.
                    'statut' => $first->end_date->gte(today()) ? 'en_cours' : ($group->every(fn ($q) => $q->is_closed) ? 'cloturee' : 'ouverte'),
                ];
            })
            ->sortByDesc('start_date')
            ->values();

        $periodeDe = [];
        foreach ($periods as $period) {
            foreach ($period->quinzaine_ids as $qid) {
                $periodeDe[$qid] = $period->key;
            }
        }

        // Net per worker and quinzaine: the snapshot of a closed one, the records of the others.
        $snapshots = QuinzaineSummary::whereIn('quinzaine_id', $quinzaines->where('is_closed', true)->pluck('id'))->get();
        $nets = [];
        foreach ($snapshots as $snap) {
            foreach ($snap->summary_data['employee_nets'] ?? [] as $empId => $net) {
                $nets[$empId][$snap->quinzaine_id] = (float) $net;
            }
        }
        PointageRecord::query()
            ->whereIn('quinzaine_id', $quinzaines->pluck('id')->diff($snapshots->pluck('quinzaine_id')))
            ->select('employee_id', 'quinzaine_id', DB::raw('SUM(net) as total_net'))
            ->groupBy('employee_id', 'quinzaine_id')
            ->get()
            ->each(function ($r) use (&$nets) {
                $nets[$r->employee_id][$r->quinzaine_id] = (float) $r->total_net;
            });

        // Up to the merged period, keeping the quinzaines: a worker moved during a period has a
        // payslip in each division they were paid in.
        $history = [];
        foreach ($nets as $empId => $parQuinzaine) {
            $lignes = [];
            foreach ($parQuinzaine as $qid => $net) {
                $key = $periodeDe[$qid] ?? null;
                if (! $key) {
                    continue;
                }
                $lignes[$key] ??= ['employee_id' => $empId, 'period_key' => $key, 'total_net' => 0, 'quinzaines' => []];
                $lignes[$key]['total_net'] += $net;
                $lignes[$key]['quinzaines'][] = $qid;
            }
            $history[(string) $empId] = array_values($lignes);
        }

        // Whoever was paid in these quinzaines — including a worker who has since moved to another
        // division, whose pay here still belongs to this division — plus the division's own
        // current workers (shown at 0 if unpaid; the page hides them by default).
        $employees = Employee::query()
            ->where(fn ($q) => $q->whereIn('id', array_keys($history))
                ->orWhere(fn ($q) => $q->when($enterpriseId, fn ($q) => $q->where('enterprise_id', $enterpriseId))
                    ->when(! $enterpriseId, fn ($q) => $q->whereHas('enterprise', fn ($eq) => $eq->where('farm_id', $farmId)))))
            ->with('enterprise:id,name')
            ->orderBy('full_name')
            ->get(['id', 'matricule', 'full_name', 'cin', 'enterprise_id', 'equipe_id', 'is_active']);

        return compact('employees', 'periods', 'history');
    }

    /** The same filters as the page: periods (the last ones by default, "toutes", or one by its key), équipe, search, workers with an amount. */
    public function filtrer(array $donnees, array $filtres): array
    {
        $periode = $filtres['periode'] ?? '';
        $periods = match (true) {
            $periode === 'toutes' => $donnees['periods'],
            $periode !== '' => $donnees['periods']->where('key', $periode)->values(),
            default => $donnees['periods']->take(self::PERIODES_PAR_DEFAUT)->values(),
        };
        $cles = $periods->pluck('key')->all();
        $total = fn ($e) => collect($donnees['history'][$e->id] ?? [])->whereIn('period_key', $cles)->sum('total_net');

        $q = mb_strtolower(trim($filtres['q'] ?? ''));
        $equipe = $filtres['equipe'] ?? '';
        $avecMontant = (bool) ($filtres['avec_montant'] ?? true);

        $employees = $donnees['employees']->filter(fn ($e) => ($q === '' || collect([$e->full_name, $e->matricule, $e->cin])->contains(fn ($v) => str_contains(mb_strtolower((string) $v), $q)))
            && ($equipe === '' || ($equipe === 'none' ? ! $e->equipe_id : (string) $e->equipe_id === $equipe))
            && (! $avecMontant || $total($e) > 0))->values();

        return ['employees' => $employees, 'periods' => $periods, 'history' => $donnees['history']];
    }
}
