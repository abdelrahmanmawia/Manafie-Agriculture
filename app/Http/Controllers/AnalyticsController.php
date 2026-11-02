<?php

namespace App\Http\Controllers;

use App\Models\Bloc;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\Sector;
use App\Services\CoutFermeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function index(Request $request, CoutFermeService $coutFerme)
    {
        $farmId = $this->scopedFarmId($request);
        $enterpriseId = $this->scopedEnterpriseId($request, $farmId);
        $blocId = $request->query('bloc_id');
        $sectorId = $request->query('sector_id');
        $selectedQuinzaineId = $request->query('quinzaine_id');
        $quinzaineFromId = $request->query('quinzaine_from');
        $quinzaineToId = $request->query('quinzaine_to');

        // 0. The periods offered, then the ones picked: one period (all its divisions, unless one
        // is filtered), an interval (either end may be left open), or all of them.
        $availableQuinzaines = Quinzaine::with('enterprise')
            ->when($enterpriseId, fn ($q) => $q->where('enterprise_id', $enterpriseId))
            ->when(! $enterpriseId && $farmId, fn ($q) => $q->whereHas('enterprise', fn ($eq) => $eq->where('farm_id', $farmId)))
            ->orderByDesc('start_date')
            ->get();

        $quinzaines = $availableQuinzaines;
        $fromQ = $quinzaineFromId ? $availableQuinzaines->firstWhere('id', $quinzaineFromId) : null;
        $toQ = $quinzaineToId ? $availableQuinzaines->firstWhere('id', $quinzaineToId) : null;

        if ($fromQ || $toQ) {
            if ($fromQ && $toQ && $fromQ->start_date > $toQ->start_date) {
                [$fromQ, $toQ] = [$toQ, $fromQ];
            }
            $quinzaines = $availableQuinzaines
                ->when($fromQ, fn ($c) => $c->filter(fn ($q) => $q->start_date >= $fromQ->start_date))
                ->when($toQ, fn ($c) => $c->filter(fn ($q) => $q->end_date <= $toQ->end_date));
        } elseif ($selectedQuinzaineId && ($selected = $availableQuinzaines->firstWhere('id', $selectedQuinzaineId))) {
            $quinzaines = $availableQuinzaines->filter(fn ($q) => $q->start_date->eq($selected->start_date) && $q->end_date->eq($selected->end_date));
        }
        $quinzaines = $quinzaines->values();
        $filteredQuinzaineIds = $quinzaines->pluck('id')->all();

        // Every pointage figure below reads through this. A day's secteur isn't pointed (only a
        // harvest has one), so the secteur filter narrows the Récolte section, not the pay.
        $records = fn () => PointageRecord::query()
            ->whereIn('pointage_records.quinzaine_id', $filteredQuinzaineIds)
            ->when($blocId, fn ($q) => $q->where('pointage_records.bloc_id', $blocId));

        // 1. Net by operation
        $opQuery = fn () => $records()->join('operations', 'pointage_records.operation_id', '=', 'operations.id');
        $opCosts = $opQuery()
            ->select('operations.name', DB::raw('SUM(net) as total_net'))
            ->groupBy('operations.name')
            ->orderByDesc('total_net')
            ->limit(10)
            ->get();
        $autresOperations = max(0, (float) $opQuery()->sum('net') - (float) $opCosts->sum('total_net'));

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

        // What the farm pays (CoutFermeService), per period: it exists per division, not per bloc.
        // Transport is farm-wide, so a division alone is shown without it.
        $parPeriode = $blocId ? [] : $coutFerme->parPeriode($quinzaines, $farmId);
        $coutPeriode = fn (array $p) => $enterpriseId ? $p['main_oeuvre'] : $p['total'];
        $cout = $blocId ? null : [
            'ttc' => round(array_sum(array_column($parPeriode, 'ttc')), 2),
            'net_direct' => round(array_sum(array_column($parPeriode, 'net_direct')), 2),
            'marge' => round(array_sum(array_column($parPeriode, 'marge')), 2),
            'transport' => $enterpriseId ? null : round(array_sum(array_column($parPeriode, 'transport')), 2),
            'total' => round(array_sum(array_map($coutPeriode, $parPeriode)), 2),
        ];

        // Area of the labour figures: the bloc when one is filtered, else the farm.
        $totalAreaHa = (float) Bloc::where('farm_id', $farmId)
            ->when($blocId, fn ($q) => $q->where('id', $blocId))
            ->sum('area_ha');

        // 3. Trend, one point per real period: grouped by its dates, not the free-text label —
        // different divisions can enter slightly different labels for the same period. A period
        // still running is marked, so its first days don't read as a collapse.
        $netParQuinzaine = $records()
            ->groupBy('pointage_records.quinzaine_id')
            ->selectRaw('pointage_records.quinzaine_id, SUM(net) as total_net')
            ->pluck('total_net', 'quinzaine_id');

        $trend = $quinzaines
            ->groupBy(fn ($q) => $q->start_date->toDateString().'_'.$q->end_date->toDateString())
            ->map(function ($group, $key) use ($netParQuinzaine, $parPeriode, $coutPeriode, $totalAreaHa) {
                $first = $group->first();
                $net = $group->sum(fn ($q) => (float) ($netParQuinzaine[$q->id] ?? 0));
                $periodeCout = isset($parPeriode[$key]) ? $coutPeriode($parPeriode[$key]) : $net;

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
        $totalNet = (float) $records()->sum('net');
        $employeeCount = $records()->distinct()->count('employee_id');

        // A worked day at its pay: an unworked J.F. and a salaried worker's 0 DH days aren't one.
        $jours = $records()->where('nature', PointageRecord::NATURE_JOUR)->where('present', true)->whereNull('quantity')->where('net', '>', 0)
            ->selectRaw('COALESCE(SUM(net), 0) as net, COUNT(*) as jours')
            ->first();
        $netMoyenParJour = $jours->jours > 0 ? $jours->net / $jours->jours : 0;

        // What's in the total but on no operation/bloc, so the charts visibly add up to it.
        $horsBlocs = $blocId ? null : [
            'jf' => (float) $records()->where('nature', PointageRecord::NATURE_JOUR)->where('present', false)->sum('net'),
            'salaires' => (float) $records()->where('nature', PointageRecord::NATURE_SALAIRE)->sum('net'),
            'a_preciser' => (float) $records()->where('nature', PointageRecord::NATURE_JOUR)->where('present', true)
                ->where(fn ($q) => $q->whereNull('operation_id')->orWhereNull('bloc_id'))->sum('net'),
        ];

        // 5. Harvest Production Analytics — always over the same dates as the pay it's compared with.
        $fromDate = $quinzaines->min('start_date');
        $toDate = $quinzaines->max('end_date');
        $toutesRecoltes = fn () => Harvest::query()
            ->where('harvests.farm_id', $farmId)
            ->when($blocId, fn ($q) => $q->where('harvests.bloc_id', $blocId))
            ->when($sectorId, fn ($q) => $q->where('harvests.sector_id', $sectorId));
        $harvestQuery = $toutesRecoltes()
            ->when($fromDate && $toDate, fn ($q) => $q->whereBetween('harvests.date', [$fromDate->toDateString(), $toDate->toDateString()]));
        $recoltesHorsPeriode = $toutesRecoltes()->count() - (clone $harvestQuery)->count();

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
        $revenuePerHa = $totalHarvestKg > 0 && $harvestAreaHa > 0 ? $totalHarvestRevenue / $harvestAreaHa : null;
        $yieldPerHa = $totalHarvestKg > 0 && $harvestAreaHa > 0 ? $totalHarvestKg / $harvestAreaHa : null;

        // Cost against production: the harvest is the whole farm's and pay has no secteur, so a
        // division or a secteur alone has no such figure, rather than a wrong one. A bloc compares
        // its net (the farm's cost isn't known per bloc); otherwise what the farm really pays.
        $coutBase = $cout['total'] ?? $totalNet;
        $ratiosComparables = ! $enterpriseId && ! $sectorId;
        $costPerKg = $ratiosComparables && $totalHarvestKg > 0 ? round($coutBase / $totalHarvestKg, 4) : null;
        $laborCostPercentage = $ratiosComparables && $totalHarvestRevenue > 0 ? ($coutBase / $totalHarvestRevenue) * 100 : null;
        $margePerHa = $ratiosComparables && $totalHarvestRevenue > 0 && $totalAreaHa > 0 ? ($totalHarvestRevenue - $coutBase) / $totalAreaHa : null;

        return Inertia::render('Admin/Analytics', [
            'opCosts' => $opCosts,
            'autresOperations' => $autresOperations,
            'blocCosts' => $blocCosts,
            'horsBlocs' => $horsBlocs,
            'trend' => $trend,
            'totalNet' => $totalNet,
            'cout' => $cout,
            'employeeCount' => $employeeCount,
            'netMoyenParJour' => $netMoyenParJour,
            'avgCostPerHa' => $avgCostPerHa,
            'selectedQuinzaineId' => $selectedQuinzaineId,
            'quinzaineFromId' => $quinzaineFromId,
            'quinzaineToId' => $quinzaineToId,
            'blocId' => $blocId,
            'sectorId' => $sectorId,
            'quinzaineOptions' => $availableQuinzaines,
            'enterprise' => $enterpriseId ? Enterprise::find($enterpriseId) : null,
            'farm' => $farmId ? Farm::find($farmId) : null,
            'enterprises' => $this->selectableEnterprises($request),
            'blocs' => $farmId ? Bloc::where('farm_id', $farmId)->get() : [],
            'sectors' => $farmId ? Sector::whereHas('bloc', fn ($q) => $q->where('farm_id', $farmId))->get() : [],
            // Harvest analytics
            'harvestByVariety' => $harvestByVariety,
            'harvestByBloc' => $harvestByBloc,
            'harvestBySector' => $harvestBySector,
            'totalHarvestKg' => $totalHarvestKg,
            'totalHarvestRevenue' => $totalHarvestRevenue,
            'recoltesHorsPeriode' => $recoltesHorsPeriode,
            'costPerKg' => $costPerKg,
            'coutBaseNet' => $cout === null,
            'ratiosComparables' => $ratiosComparables,
            'totalAreaHa' => $totalAreaHa,
            // Productivity metrics
            'revenuePerHa' => $revenuePerHa,
            'yieldPerHa' => $yieldPerHa,
            'laborCostPercentage' => $laborCostPercentage,
            'margePerHa' => $margePerHa,
        ]);
    }
}
