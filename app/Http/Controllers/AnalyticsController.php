<?php

namespace App\Http\Controllers;

use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\QuinzaineSummary;
use App\Models\Enterprise;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\Bloc;
use App\Models\Harvest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);
        $enterpriseId = $this->scopedEnterpriseId($request, $farmId);
        $blocId = $request->query('bloc_id');
        $sectorId = $request->query('sector_id');
        $selectedQuinzaineId = $request->query('quinzaine_id');
        $quinzaineFromId = $request->query('quinzaine_from');
        $quinzaineToId = $request->query('quinzaine_to');

        // 0. Build base quinzaine list for selection
        $quinzaineOptionsQuery = Quinzaine::query()
            ->when($enterpriseId, fn($q) => $q->where('enterprise_id', $enterpriseId))
            ->when(!$enterpriseId && $farmId, fn($q) => $q->whereHas('enterprise', fn($eq) => $eq->where('farm_id', $farmId)));

        $availableQuinzaines = (clone $quinzaineOptionsQuery)->orderByDesc('start_date')->get();

        // 0.1 Determine the filtered quinzaines and their date range
        $filteredQuinzaineIds = [];
        $minQuinzaineStartDate = null;
        $maxQuinzaineEndDate = null;

        if ($quinzaineFromId && $quinzaineToId) {
            $fromQ = Quinzaine::find($quinzaineFromId);
            $toQ = Quinzaine::find($quinzaineToId);

            if ($fromQ && $toQ) {
                if ($fromQ->start_date > $toQ->start_date) {
                    $temp = $fromQ; $fromQ = $toQ; $toQ = $temp;
                }

                $filteredQuinzaines = (clone $quinzaineOptionsQuery)
                    ->where('start_date', '>=', $fromQ->start_date)
                    ->where('end_date', '<=', $toQ->end_date)
                    ->get();
                $filteredQuinzaineIds = $filteredQuinzaines->pluck('id')->toArray();
                $minQuinzaineStartDate = $fromQ->start_date;
                $maxQuinzaineEndDate = $toQ->end_date;
            }
        } elseif ($selectedQuinzaineId) {
            $latestQuinzaine = $availableQuinzaines->firstWhere('id', $selectedQuinzaineId);
            if ($latestQuinzaine) {
                if ($enterpriseId) {
                    $filteredQuinzaineIds = [$latestQuinzaine->id];
                    $minQuinzaineStartDate = $latestQuinzaine->start_date;
                    $maxQuinzaineEndDate = $latestQuinzaine->end_date;
                } else {
                    $quinzainesForPeriod = Quinzaine::where('start_date', $latestQuinzaine->start_date)
                        ->where('end_date', $latestQuinzaine->end_date)
                        ->whereIn('enterprise_id', function($q) use ($farmId) {
                            $q->select('id')->from('enterprises')->where('farm_id', $farmId);
                        })->get();
                    $filteredQuinzaineIds = $quinzainesForPeriod->pluck('id')->toArray();
                    $minQuinzaineStartDate = $latestQuinzaine->start_date;
                    $maxQuinzaineEndDate = $latestQuinzaine->end_date;
                }
            }
        } else {
            $filteredQuinzaineIds = $availableQuinzaines->pluck('id')->toArray();
        }

        // Harvests are only period-filtered when the user explicitly picks a quinzaine/range —
        // otherwise recent harvests dated outside the (often old) pay-period window would silently disappear.
        $hasExplicitPeriodFilter = $minQuinzaineStartDate && $maxQuinzaineEndDate;

        // 1. Cost by Operation
        $opCosts = PointageRecord::query()
            ->join('operations', 'pointage_records.operation_id', '=', 'operations.id')
            ->whereIn('pointage_records.quinzaine_id', $filteredQuinzaineIds)
            ->when($blocId, fn($q) => $q->where('pointage_records.bloc_id', $blocId))
            ->when($sectorId, fn($q) => $q->where('pointage_records.sector_id', $sectorId))
            ->select('operations.name', DB::raw('SUM(net) as total_net'))
            ->groupBy('operations.name')
            ->orderByDesc('total_net')
            ->limit(10)
            ->get();

        // 2. Cost by Bloc — including area_ha for Charge/Ha calculation
        $blocCosts = PointageRecord::query()
            ->join('blocs', 'pointage_records.bloc_id', '=', 'blocs.id')
            ->whereIn('pointage_records.quinzaine_id', $filteredQuinzaineIds)
            ->when($blocId, fn($q) => $q->where('pointage_records.bloc_id', $blocId))
            ->when($sectorId, fn($q) => $q->where('pointage_records.sector_id', $sectorId))
            ->select(
                'blocs.id as bloc_id',
                'blocs.name',
                'blocs.area_ha',
                DB::raw('SUM(net) as total_net'),
                DB::raw('SUM(hours) as total_hours')
            )
            ->groupBy('blocs.id', 'blocs.name', 'blocs.area_ha')
            ->get()
            ->map(function ($b) {
                $b->charge_per_ha = ($b->area_ha > 0)
                    ? round($b->total_net / $b->area_ha, 2)
                    : null;
                return $b;
            });

        // 3. Trend
        $trend = Quinzaine::query()
            ->whereIn('id', $filteredQuinzaineIds)
            ->select('id', 'start_date', 'end_date', 'label')
            ->get()
            ->map(function($q) use ($blocId, $sectorId, $farmId) {
                $totals = PointageRecord::where('quinzaine_id', $q->id)
                    ->when($blocId, fn($query) => $query->where('bloc_id', $blocId))
                    ->when($sectorId, fn($query) => $query->where('sector_id', $sectorId))
                    ->selectRaw('SUM(net) as total_net, SUM(hours) as total_hours')
                    ->first();

                $q->total_net = $totals->total_net ?? 0;
                $q->total_hours = $totals->total_hours ?? 0;
                $q->cost_per_hour = $q->total_hours > 0 ? round($q->total_net / $q->total_hours, 2) : 0;

                // Calculate cost per hectare for this quinzaine
                $quinzaineAreaHa = Bloc::where('farm_id', $farmId)
                    ->when($blocId, fn($query) => $query->where('id', $blocId))
                    ->sum('area_ha');
                $q->cost_per_ha = $quinzaineAreaHa > 0 ? round($q->total_net / $quinzaineAreaHa, 2) : 0;

                return $q;
            })
            // Group by the actual date range, not the free-text label — different divisions can
            // enter slightly different labels for what is otherwise the same real-world period,
            // which would otherwise fragment one period into multiple trend points.
            ->groupBy(fn($q) => $q->start_date->format('Y-m-d') . '_' . $q->end_date->format('Y-m-d'))
            ->map(function($group) {
                $first = $group->first();
                return (object)[
                    'start_date' => $first->start_date,
                    'label' => $first->label,
                    'total_net' => $group->sum('total_net'),
                    'total_hours' => $group->sum('total_hours'),
                    'cost_per_hour' => $group->sum('total_hours') > 0 ? round($group->sum('total_net') / $group->sum('total_hours'), 2) : 0,
                    'cost_per_ha' => $group->sum('cost_per_ha'),
                ];
            })
            ->values()
            ->sortBy('start_date')
            ->values();

        // 4. Totals
        $totalEmployees = Employee::query()
            ->when($enterpriseId, fn($q) => $q->where('enterprise_id', $enterpriseId))
            ->when(!$enterpriseId && $farmId, fn($q) => $q->whereHas('enterprise', fn($eq) => $eq->where('farm_id', $farmId)))
            ->count();

        $recordTotals = PointageRecord::query()
            ->whereIn('quinzaine_id', $filteredQuinzaineIds)
            ->when($blocId, fn($q) => $q->where('bloc_id', $blocId))
            ->when($sectorId, fn($q) => $q->where('sector_id', $sectorId))
            ->selectRaw('COALESCE(SUM(net), 0) as total_net, COALESCE(SUM(hours), 0) as total_hours')
            ->first();

        $activeEmployeeCount = PointageRecord::query()
            ->whereIn('quinzaine_id', $filteredQuinzaineIds)
            ->when($blocId, fn($q) => $q->where('bloc_id', $blocId))
            ->when($sectorId, fn($q) => $q->where('sector_id', $sectorId))
            ->distinct()
            ->count('employee_id');

        $avgNetPerEmployee = $activeEmployeeCount > 0 ? $recordTotals->total_net / $activeEmployeeCount : 0;
        $avgCostPerHour = $recordTotals->total_hours > 0 ? $recordTotals->total_net / $recordTotals->total_hours : 0;

        // Calculate total area for cost per hectare
        $totalAreaHa = Bloc::where('farm_id', $farmId)
            ->when($blocId, fn($q) => $q->where('id', $blocId))
            ->sum('area_ha');
        $avgCostPerHa = $totalAreaHa > 0 ? ($recordTotals->total_net ?? 0) / $totalAreaHa : 0;

        // 5. Harvest Production Analytics
        $harvestQuery = Harvest::query()
            ->where('harvests.farm_id', $farmId)
            ->when($blocId, fn($q) => $q->where('harvests.bloc_id', $blocId))
            ->when($sectorId, fn($q) => $q->where('harvests.sector_id', $sectorId))
            // Only narrow to a date range when the user explicitly picked a quinzaine/period —
            // otherwise harvests recorded outside the pay-period window would be silently hidden.
            ->when($hasExplicitPeriodFilter, function ($q) use ($minQuinzaineStartDate, $maxQuinzaineEndDate) {
                $q->whereBetween('harvests.date', [$minQuinzaineStartDate, $maxQuinzaineEndDate]);
            });

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

        $totalHarvestKg = (clone $harvestQuery)->sum('quantity_kg');
        $totalHarvestRevenue = (clone $harvestQuery)->sum('total_revenue_dh');

        // Cost per Kg: total labor net / total harvest kg (for current filters)
        $costPerKg = $totalHarvestKg > 0
            ? round(($recordTotals->total_net ?? 0) / $totalHarvestKg, 4)
            : null;

        // Additional productivity metrics
        $revenuePerHa = $totalAreaHa > 0 ? $totalHarvestRevenue / $totalAreaHa : 0;
        $yieldPerHa = $totalAreaHa > 0 ? $totalHarvestKg / $totalAreaHa : 0;
        $laborCostPercentage = $totalHarvestRevenue > 0 ? (($recordTotals->total_net ?? 0) / $totalHarvestRevenue) * 100 : 0;
        $profitPerHa = $totalAreaHa > 0 ? ($totalHarvestRevenue - ($recordTotals->total_net ?? 0)) / $totalAreaHa : 0;

        return Inertia::render('Admin/Analytics', [
            'opCosts' => $opCosts,
            'blocCosts' => $blocCosts,
            'trend' => $trend,
            'totalNet' => $recordTotals->total_net ?? 0,
            'employeeCount' => $activeEmployeeCount, // Changed to activeEmployeeCount for consistency
            'avgNetPerEmployee' => $avgNetPerEmployee,
            'avgCostPerHour' => $avgCostPerHour,
            'avgCostPerHa' => $avgCostPerHa,
            'selectedQuinzaineId' => $selectedQuinzaineId,
            'quinzaineFromId' => $quinzaineFromId,
            'quinzaineToId' => $quinzaineToId,
            'blocId' => $blocId,
            'sectorId' => $sectorId,
            'quinzaineOptions' => $availableQuinzaines,
            'enterprise' => $enterpriseId ? Enterprise::find($enterpriseId) : null,
            'farm' => $farmId ? Farm::find($farmId) : null,
            'enterprises' => $farmId ? Enterprise::where('farm_id', $farmId)->get() : [],
            'blocs' => $farmId ? Bloc::where('farm_id', $farmId)->get() : [],
            'sectors' => $farmId ? \App\Models\Sector::whereHas('bloc', fn($q) => $q->where('farm_id', $farmId))->get() : [],
            // Harvest analytics
            'harvestByVariety' => $harvestByVariety,
            'harvestByBloc' => $harvestByBloc,
            'harvestBySector' => $harvestBySector,
            'totalHarvestKg' => $totalHarvestKg,
            'totalHarvestRevenue' => $totalHarvestRevenue,
            'costPerKg' => $costPerKg,
            'totalAreaHa' => $totalAreaHa,
            // Productivity metrics
            'revenuePerHa' => $revenuePerHa,
            'yieldPerHa' => $yieldPerHa,
            'laborCostPercentage' => $laborCostPercentage,
            'profitPerHa' => $profitPerHa,
        ]);
    }
}
