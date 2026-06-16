<?php

namespace App\Http\Controllers;

use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\QuinzaineSummary;
use App\Models\Enterprise;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\Bloc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $request->query('farm_id') ?: ($request->user()->farm_id);
        $enterpriseId = $request->query('enterprise_id');
        $blocId = $request->query('bloc_id');
        $selectedQuinzaineId = $request->query('quinzaine_id');
        $quinzaineFromId = $request->query('quinzaine_from');
        $quinzaineToId = $request->query('quinzaine_to');

        // Permission check
        if ($request->user()->role !== 'super_admin' && $request->user()->farm_id != $farmId) {
            if (!$request->user()->farm_id) abort(403);
            $farmId = $request->user()->farm_id;
        }

        // 0. Build base quinzaine list for selection
        $quinzaineOptionsQuery = Quinzaine::query()
            ->when($enterpriseId, fn($q) => $q->where('enterprise_id', $enterpriseId))
            ->when(!$enterpriseId && $farmId, fn($q) => $q->whereHas('enterprise', fn($eq) => $eq->where('farm_id', $farmId)));

        $availableQuinzaines = (clone $quinzaineOptionsQuery)->orderByDesc('start_date')->get();

        // 0.1 Determine the filtered quinzaines
        $filteredQuinzaineIds = [];
        
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
            }
        } elseif ($selectedQuinzaineId) {
            $latestQuinzaine = $availableQuinzaines->firstWhere('id', $selectedQuinzaineId);
            if ($latestQuinzaine) {
                if ($enterpriseId) {
                    $filteredQuinzaineIds = [$latestQuinzaine->id];
                } else {
                    $filteredQuinzaineIds = Quinzaine::where('start_date', $latestQuinzaine->start_date)
                        ->where('end_date', $latestQuinzaine->end_date)
                        ->whereIn('enterprise_id', function($q) use ($farmId) {
                            $q->select('id')->from('enterprises')->where('farm_id', $farmId);
                        })->pluck('id')->toArray();
                }
            }
        } else {
            $filteredQuinzaineIds = $availableQuinzaines->pluck('id')->toArray();
        }

        // 1. Cost by Operation
        $opCosts = PointageRecord::query()
            ->join('operations', 'pointage_records.operation_id', '=', 'operations.id')
            ->whereIn('pointage_records.quinzaine_id', $filteredQuinzaineIds)
            ->when($blocId, fn($q) => $q->where('pointage_records.bloc_id', $blocId))
            ->select('operations.name', DB::raw('SUM(net) as total_net'))
            ->groupBy('operations.name')
            ->orderByDesc('total_net')
            ->limit(10)
            ->get();

        // 2. Cost by Bloc (If bloc is selected, this is just one bar)
        $blocCosts = PointageRecord::query()
            ->join('blocs', 'pointage_records.bloc_id', '=', 'blocs.id')
            ->whereIn('pointage_records.quinzaine_id', $filteredQuinzaineIds)
            ->when($blocId, fn($q) => $q->where('pointage_records.bloc_id', $blocId))
            ->select('blocs.name', DB::raw('SUM(net) as total_net'))
            ->groupBy('blocs.name')
            ->get();

        // 3. Trend
        $trend = Quinzaine::query()
            ->whereIn('id', $filteredQuinzaineIds)
            ->select('id', 'start_date', 'label')
            ->get()
            ->map(function($q) use ($blocId) {
                $totals = PointageRecord::where('quinzaine_id', $q->id)
                    ->when($blocId, fn($query) => $query->where('bloc_id', $blocId))
                    ->selectRaw('SUM(net) as total_net, SUM(hours) as total_hours')
                    ->first();
                
                $q->total_net = $totals->total_net ?? 0;
                $q->total_hours = $totals->total_hours ?? 0;
                $q->cost_per_hour = $q->total_hours > 0 ? round($q->total_net / $q->total_hours, 2) : 0;
                return $q;
            })
            ->groupBy(fn($q) => $q->start_date . '_' . $q->label)
            ->map(function($group) {
                $first = $group->first();
                return (object)[
                    'start_date' => $first->start_date,
                    'label' => $first->label,
                    'total_net' => $group->sum('total_net'),
                    'total_hours' => $group->sum('total_hours'),
                    'cost_per_hour' => $group->sum('total_hours') > 0 ? round($group->sum('total_net') / $group->sum('total_hours'), 2) : 0
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
            ->selectRaw('COALESCE(SUM(net), 0) as total_net, COALESCE(SUM(hours), 0) as total_hours')
            ->first();

        $activeEmployeeCount = PointageRecord::query()
            ->whereIn('quinzaine_id', $filteredQuinzaineIds)
            ->when($blocId, fn($q) => $q->where('bloc_id', $blocId))
            ->distinct()
            ->count('employee_id');

        $avgNetPerEmployee = $activeEmployeeCount > 0 ? $recordTotals->total_net / $activeEmployeeCount : 0;
        $avgCostPerHour = $recordTotals->total_hours > 0 ? $recordTotals->total_net / $recordTotals->total_hours : 0;

        return Inertia::render('Admin/Analytics', [
            'opCosts' => $opCosts,
            'blocCosts' => $blocCosts,
            'trend' => $trend,
            'totalNet' => $recordTotals->total_net ?? 0,
            'employeeCount' => $totalEmployees,
            'avgNetPerEmployee' => $avgNetPerEmployee,
            'avgCostPerHour' => $avgCostPerHour,
            'selectedQuinzaineId' => $selectedQuinzaineId,
            'quinzaineFromId' => $quinzaineFromId,
            'quinzaineToId' => $quinzaineToId,
            'blocId' => $blocId,
            'quinzaineOptions' => $availableQuinzaines,
            'enterprise' => $enterpriseId ? Enterprise::find($enterpriseId) : null,
            'farm' => $farmId ? Farm::find($farmId) : null,
            'farms' => $request->user()->role === 'super_admin' ? Farm::all() : [],
            'enterprises' => $farmId ? Enterprise::where('farm_id', $farmId)->get() : [],
            'blocs' => $farmId ? Bloc::where('farm_id', $farmId)->get() : []
        ]);
    }
}
