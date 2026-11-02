<?php

namespace App\Services;

use App\Models\Bloc;
use App\Models\Harvest;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\Sector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the Analyses page and its Excel export, for a farm (or one division), a bloc, a
 * secteur and periods. The net paid and what the farm pays (CoutFermeService) are shown apart; a
 * figure that can't be right for the filters is null rather than wrong.
 */
class AnalyticsService
{
    public function __construct(private CoutFermeService $coutFerme) {}

    /** @param  array  $filtres  bloc_id, sector_id, quinzaine_id, quinzaine_from, quinzaine_to */
    public function calculer(int $farmId, ?int $enterpriseId, array $filtres): array
    {
        $blocId = $filtres['bloc_id'] ?? null;
        $sectorId = $filtres['sector_id'] ?? null;

        // 0. The periods offered, then the ones picked: one period (all its divisions, unless one
        // is filtered), an interval (either end may be left open), or all of them.
        $availableQuinzaines = Quinzaine::with('enterprise')
            ->when($enterpriseId, fn ($q) => $q->where('enterprise_id', $enterpriseId))
            ->when(! $enterpriseId, fn ($q) => $q->whereHas('enterprise', fn ($eq) => $eq->where('farm_id', $farmId)))
            ->orderByDesc('start_date')
            ->get();

        $quinzaines = $this->periodesChoisies($availableQuinzaines, $filtres);
        $quinzaineIds = $quinzaines->pluck('id')->all();

        // Area of the labour figures: the bloc when one is filtered, else the farm.
        $totalAreaHa = (float) Bloc::where('farm_id', $farmId)
            ->when($blocId, fn ($q) => $q->where('id', $blocId))
            ->sum('area_ha');

        // Every pointage figure below reads through this. A day's secteur isn't pointed (only a
        // harvest has one), so the secteur filter narrows the Récolte section, not the pay.
        $records = fn () => $this->records($quinzaineIds, $blocId);

        // 1. Net by operation, costliest first; the page charts the top 10, the export has them all.
        $operations = $records()
            ->join('operations', 'pointage_records.operation_id', '=', 'operations.id')
            ->select('operations.name', DB::raw('SUM(net) as total_net'))
            ->groupBy('operations.name')
            ->orderByDesc('total_net')
            ->get();
        $opCosts = $operations->take(10)->values();

        // 2. Net by bloc, the costliest first — including area_ha for Charge/Ha calculation
        $blocCosts = $records()
            ->join('blocs', 'pointage_records.bloc_id', '=', 'blocs.id')
            ->select('blocs.id as bloc_id', 'blocs.name', 'blocs.area_ha', DB::raw('SUM(net) as total_net'))
            ->groupBy('blocs.id', 'blocs.name', 'blocs.area_ha')
            ->orderByDesc('total_net')
            ->get()
            ->map(function ($b) {
                $b->charge_per_ha = ($b->area_ha > 0) ? round($b->total_net / $b->area_ha, 2) : null;

                return $b;
            });

        // What the farm pays, per period: it exists per division, not per bloc. Transport is
        // farm-wide, so a division alone is shown without it.
        $parPeriode = $blocId ? [] : $this->coutFerme->parPeriode($quinzaines, $farmId);
        $cout = $blocId ? null : $this->coutTotal($parPeriode, $enterpriseId);

        // 3. Trend, one point per real period: grouped by its dates, not the free-text label —
        // different divisions can enter slightly different labels for the same period. A period
        // still running is marked, so its first days don't read as a collapse.
        $netParQuinzaine = $records()
            ->groupBy('pointage_records.quinzaine_id')
            ->selectRaw('pointage_records.quinzaine_id, SUM(net) as total_net')
            ->pluck('total_net', 'quinzaine_id');

        $trend = $quinzaines
            ->groupBy(fn ($q) => $this->clePeriode($q))
            ->map(function ($group, $key) use ($netParQuinzaine, $parPeriode, $enterpriseId, $totalAreaHa) {
                $first = $group->first();
                $net = $group->sum(fn ($q) => (float) ($netParQuinzaine[$q->id] ?? 0));
                $periodeCout = isset($parPeriode[$key]) ? $this->coutTotal([$parPeriode[$key]], $enterpriseId)['total'] : $net;

                return (object) [
                    'start_date' => $first->start_date,
                    'label' => $first->label,
                    'total_net' => $net,
                    'cost_per_ha' => $totalAreaHa > 0 ? round($periodeCout / $totalAreaHa, 2) : 0,
                    'en_cours' => $first->end_date->gte(today()),
                ];
            })
            ->sortBy('start_date')
            ->values();

        // The average per quinzaine leaves out the period still running, unless it's the only one.
        $finies = $trend->where('en_cours', false);
        $avgCostPerHa = (float) (($finies->isNotEmpty() ? $finies : $trend)->avg('cost_per_ha') ?? 0);

        // 4. Totals
        $chiffres = $this->chiffres($quinzaineIds, $blocId);

        // What's in the total but on no operation/bloc, so the charts visibly add up to it.
        $horsBlocs = $blocId ? null : [
            'jf' => (float) $records()->where('nature', PointageRecord::NATURE_JOUR)->where('present', false)->sum('net'),
            'salaires' => (float) $records()->where('nature', PointageRecord::NATURE_SALAIRE)->sum('net'),
            'a_preciser' => (float) $records()->where('nature', PointageRecord::NATURE_JOUR)->where('present', true)
                ->where(fn ($q) => $q->whereNull('operation_id')->orWhereNull('bloc_id'))->sum('net'),
        ];

        // One period picked: how it compares with the one before, once it's over.
        $periodeEnCours = false;
        $precedente = null;
        $periodes = $quinzaines->groupBy(fn ($q) => $this->clePeriode($q));
        if ($periodes->count() === 1) {
            $periodeEnCours = $quinzaines->first()->end_date->gte(today());
            if (! $periodeEnCours) {
                $precedente = $this->periodePrecedente($availableQuinzaines, $quinzaines->first(), $blocId, $enterpriseId, $farmId, $totalAreaHa);
            }
        }

        return [
            'opCosts' => $opCosts,
            'operations' => $operations,
            'autresOperations' => max(0, (float) $operations->sum('total_net') - (float) $opCosts->sum('total_net')),
            'blocCosts' => $blocCosts,
            'horsBlocs' => $horsBlocs,
            'trend' => $trend,
            'totalNet' => $chiffres['net'],
            'cout' => $cout,
            'employeeCount' => $chiffres['employes'],
            'netMoyenParJour' => $chiffres['net_par_jour'],
            'avgCostPerHa' => $avgCostPerHa,
            'precedente' => $precedente,
            'periodeEnCours' => $periodeEnCours,
            'quinzaineOptions' => $availableQuinzaines,
            'totalAreaHa' => $totalAreaHa,
            'coutBaseNet' => $cout === null,
        ] + $this->production($quinzaines, $farmId, $enterpriseId, $blocId, $sectorId, $totalAreaHa, $cout['total'] ?? $chiffres['net']);
    }

    private function periodesChoisies(Collection $available, array $filtres): Collection
    {
        $fromQ = ! empty($filtres['quinzaine_from']) ? $available->firstWhere('id', $filtres['quinzaine_from']) : null;
        $toQ = ! empty($filtres['quinzaine_to']) ? $available->firstWhere('id', $filtres['quinzaine_to']) : null;

        if ($fromQ || $toQ) {
            if ($fromQ && $toQ && $fromQ->start_date > $toQ->start_date) {
                [$fromQ, $toQ] = [$toQ, $fromQ];
            }

            return $available
                ->when($fromQ, fn ($c) => $c->filter(fn ($q) => $q->start_date >= $fromQ->start_date))
                ->when($toQ, fn ($c) => $c->filter(fn ($q) => $q->end_date <= $toQ->end_date))
                ->values();
        }

        $selected = ! empty($filtres['quinzaine_id']) ? $available->firstWhere('id', $filtres['quinzaine_id']) : null;

        return $selected
            ? $available->filter(fn ($q) => $q->start_date->eq($selected->start_date) && $q->end_date->eq($selected->end_date))->values()
            : $available;
    }

    private function records(array $quinzaineIds, $blocId)
    {
        return PointageRecord::query()
            ->whereIn('pointage_records.quinzaine_id', $quinzaineIds)
            ->when($blocId, fn ($q) => $q->where('pointage_records.bloc_id', $blocId));
    }

    /** The net, who was paid, and a worked day at its pay: an unworked J.F. and a salaried worker's 0 DH days aren't one. */
    private function chiffres(array $quinzaineIds, $blocId): array
    {
        $jours = $this->records($quinzaineIds, $blocId)
            ->where('nature', PointageRecord::NATURE_JOUR)->where('present', true)->whereNull('quantity')->where('net', '>', 0)
            ->selectRaw('COALESCE(SUM(net), 0) as net, COUNT(*) as jours')
            ->first();

        return [
            'net' => (float) $this->records($quinzaineIds, $blocId)->sum('net'),
            'employes' => $this->records($quinzaineIds, $blocId)->distinct()->count('employee_id'),
            'net_par_jour' => $jours->jours > 0 ? $jours->net / $jours->jours : 0,
        ];
    }

    private function coutTotal(array $parPeriode, ?int $enterpriseId): array
    {
        return [
            'ttc' => round(array_sum(array_column($parPeriode, 'ttc')), 2),
            'net_direct' => round(array_sum(array_column($parPeriode, 'net_direct')), 2),
            'marge' => round(array_sum(array_column($parPeriode, 'marge')), 2),
            'transport' => $enterpriseId ? null : round(array_sum(array_column($parPeriode, 'transport')), 2),
            'total' => round(array_sum(array_column($parPeriode, $enterpriseId ? 'main_oeuvre' : 'total')), 2),
        ];
    }

    private function clePeriode(Quinzaine $q): string
    {
        return $q->start_date->toDateString().'_'.$q->end_date->toDateString();
    }

    /** The same figures for the last period before this one, null when there's none. */
    private function periodePrecedente(Collection $available, Quinzaine $periode, $blocId, ?int $enterpriseId, int $farmId, float $areaHa): ?array
    {
        $avant = $available->filter(fn ($q) => $q->start_date < $periode->start_date);
        if ($avant->isEmpty()) {
            return null;
        }
        $debut = $avant->max('start_date');
        $quinzaines = $avant->filter(fn ($q) => $q->start_date->eq($debut))->values();
        $chiffres = $this->chiffres($quinzaines->pluck('id')->all(), $blocId);
        $cout = $blocId ? null : $this->coutTotal($this->coutFerme->parPeriode($quinzaines, $farmId), $enterpriseId);
        $base = $cout['total'] ?? $chiffres['net'];

        return $chiffres + [
            'start_date' => $debut,
            'cout' => $cout['total'] ?? null,
            'cost_per_ha' => $areaHa > 0 ? round($base / $areaHa, 2) : 0,
        ];
    }

    /** 5. Harvest figures — always over the same dates as the pay they're compared with. */
    private function production(Collection $quinzaines, int $farmId, ?int $enterpriseId, $blocId, $sectorId, float $totalAreaHa, float $coutBase): array
    {
        $fromDate = $quinzaines->min('start_date');
        $toDate = $quinzaines->max('end_date');
        $toutesRecoltes = fn () => Harvest::query()
            ->where('harvests.farm_id', $farmId)
            ->when($blocId, fn ($q) => $q->where('harvests.bloc_id', $blocId))
            ->when($sectorId, fn ($q) => $q->where('harvests.sector_id', $sectorId));
        $harvestQuery = $toutesRecoltes()
            ->when($fromDate && $toDate, fn ($q) => $q->whereBetween('harvests.date', [$fromDate->toDateString(), $toDate->toDateString()]));

        // Total harvest by variety
        $harvestByVariety = (clone $harvestQuery)
            ->select('variety', DB::raw('SUM(quantity_kg) as total_kg'), DB::raw('SUM(total_revenue_dh) as total_revenue'))
            ->groupBy('variety')
            ->orderByDesc('total_kg')
            ->get();

        // Total harvest by bloc with yield/ha
        $harvestByBloc = (clone $harvestQuery)
            ->join('blocs', 'harvests.bloc_id', '=', 'blocs.id')
            ->select(
                'blocs.name',
                'blocs.area_ha',
                DB::raw('SUM(harvests.quantity_kg) as total_kg'),
                DB::raw('SUM(harvests.total_revenue_dh) as total_revenue')
            )
            ->groupBy('blocs.name', 'blocs.area_ha')
            ->get()
            ->map(function ($h) {
                $h->yield_per_ha = ($h->area_ha > 0) ? round($h->total_kg / $h->area_ha, 2) : null;

                return $h;
            });

        // Total harvest by sector with yield/ha and yield/tree
        $harvestBySector = (clone $harvestQuery)
            ->join('sectors', 'harvests.sector_id', '=', 'sectors.id')
            ->join('blocs', 'sectors.bloc_id', '=', 'blocs.id') // Join with blocs table
            ->select(
                'sectors.name',
                'sectors.area_ha',
                'sectors.total_trees',
                'blocs.name as bloc_name', // Select bloc name
                DB::raw('SUM(harvests.quantity_kg) as total_kg'),
                DB::raw('SUM(harvests.total_revenue_dh) as total_revenue')
            )
            ->groupBy('sectors.name', 'sectors.area_ha', 'sectors.total_trees', 'blocs.name') // Add bloc name to groupBy
            ->get()
            ->map(function ($h) {
                $h->yield_per_ha = ($h->area_ha > 0) ? round($h->total_kg / $h->area_ha, 2) : null;
                $h->yield_per_tree = ($h->total_trees > 0) ? round($h->total_kg / $h->total_trees, 3) : null;

                return $h;
            });

        $totalHarvestKg = (float) (clone $harvestQuery)->sum('quantity_kg');
        $totalHarvestRevenue = (float) (clone $harvestQuery)->sum('total_revenue_dh');

        // Yield and revenue per hectare of what was harvested: the secteur when one is filtered.
        $sector = $sectorId ? Sector::whereKey($sectorId)->whereHas('bloc', fn ($q) => $q->where('farm_id', $farmId))->first() : null;
        $harvestAreaHa = $sector ? (float) $sector->area_ha : $totalAreaHa;

        // Cost against production: the harvest is the whole farm's and pay has no secteur, so a
        // division or a secteur alone has no such figure, rather than a wrong one. A bloc compares
        // its net (the farm's cost isn't known per bloc); otherwise what the farm really pays.
        $ratiosComparables = ! $enterpriseId && ! $sectorId;

        return [
            'harvestByVariety' => $harvestByVariety,
            'harvestByBloc' => $harvestByBloc,
            'harvestBySector' => $harvestBySector,
            'totalHarvestKg' => $totalHarvestKg,
            'totalHarvestRevenue' => $totalHarvestRevenue,
            'recoltesHorsPeriode' => $toutesRecoltes()->count() - (clone $harvestQuery)->count(),
            'ratiosComparables' => $ratiosComparables,
            'costPerKg' => $ratiosComparables && $totalHarvestKg > 0 ? round($coutBase / $totalHarvestKg, 4) : null,
            'revenuePerHa' => $totalHarvestKg > 0 && $harvestAreaHa > 0 ? $totalHarvestRevenue / $harvestAreaHa : null,
            'yieldPerHa' => $totalHarvestKg > 0 && $harvestAreaHa > 0 ? $totalHarvestKg / $harvestAreaHa : null,
            'laborCostPercentage' => $ratiosComparables && $totalHarvestRevenue > 0 ? ($coutBase / $totalHarvestRevenue) * 100 : null,
            'margePerHa' => $ratiosComparables && $totalHarvestRevenue > 0 && $totalAreaHa > 0 ? ($totalHarvestRevenue - $coutBase) / $totalAreaHa : null,
        ];
    }
}
