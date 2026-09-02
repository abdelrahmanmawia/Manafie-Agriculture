<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Quinzaine;
use App\Models\PointageRecord;
use App\Models\Enterprise;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

use App\Services\PayrollService;
use Barryvdh\DomPDF\Facade\Pdf;

class PayrollController extends Controller
{
    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    /**
     * A quinzaine belongs to exactly one enterprise; confirm the requesting user is actually
     * scoped to it before handing back any of its payroll data — same check as
     * PointageController::grid, since payslips are at least as sensitive as the grid itself.
     */
    private function assertQuinzaineInScope(Request $request, Quinzaine $quinzaine): void
    {
        $user = $request->user();
        if ($user->enterprise_id) {
            abort_unless($quinzaine->enterprise_id === $user->enterprise_id, 403);
        } elseif ($user->farm_id) {
            abort_unless($quinzaine->enterprise->farm_id === $user->farm_id, 403);
        } elseif ($user->role === 'super_admin') {
            abort_unless($quinzaine->enterprise->farm_id === (int) session('active_farm_id'), 403);
        } else {
            abort(403);
        }
    }

    public function downloadGeneralPayslip(Request $request, $quinzaineId)
    {
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        $this->assertQuinzaineInScope($request, $quinzaine);

        $employees = Employee::where('enterprise_id', $quinzaine->enterprise_id)->orderBy('full_name')->get();

        $records = PointageRecord::where('quinzaine_id', $quinzaineId)
            ->get()
            ->groupBy('employee_id');

        $pdf = Pdf::loadView('exports.general_payslip', [
            'quinzaine' => $quinzaine,
            'employees' => $employees,
            'records' => $records,
            'payrollService' => $this->payrollService
        ])->setPaper('a4', 'portrait');

        return $pdf->download('Etat_Global_' . ($quinzaine->label ?: 'Pointage') . '.pdf');
    }

    public function downloadPayslip(Request $request, $employeeId, $quinzaineId)
    {
        $employee = Employee::findOrFail($employeeId);
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        $this->assertQuinzaineInScope($request, $quinzaine);
        // Employee is farm-scoped, not enterprise-scoped (the same real worker can have pointage
        // under several enterprises of the same farm over time) — so the real invariant is same
        // farm, not same enterprise; never loosens across farms.
        abort_unless($employee->farm_id === $quinzaine->enterprise->farm_id, 403);

        $records = PointageRecord::where('employee_id', $employeeId)
            ->where('quinzaine_id', $quinzaineId)
            ->get();

        if ($records->isEmpty()) {
            return redirect()->back()->with('error', 'Aucun pointage trouvé pour ce salarié.');
        }

        // Calculate Totals for the PDF
        $totalNet = 0;
        $totalGains = 0;
        $totalRetenues = 0;

        foreach($records as $record) {
            // Use this RECORD's own frozen rate, not the enterprise's CURRENT default_brut_rate
            // — a closed quinzaine's numbers must not silently drift if the rate changes later
            // (see PointageExport.php's own comment on the same principle). totalNet is summed
            // straight from $record->net (never recomputed) so it always matches the Grid/Excel
            // export exactly; $calc here is only used to reconstruct the gains/deductions
            // breakdown display below.
            $calc = $this->payrollService->calculate(
                $quinzaine->enterprise->contract_type,
                $record->rate,
                $record->hours,
                $employee->complement,
                $record->is_jf,
                $quinzaine->enterprise->invoiced_to_client
            );
            $totalNet += $record->net;

            // Simplified Gain/Deduction math for the PDF view — reuse the service's own hs_pay
            // rather than re-deriving the HS rate here, so this always matches PayrollService::calculate().
            // Complement/prime only exists for avec_contrat in PayrollService::calculate() (sans_contrat's
            // total_net never includes it), so mirror that here too or totalGains/totalRetenues drift from
            // the real net pay. On a JF day for avec_contrat, $calc['sal_net_j'] already folds it in, so
            // it's only added as its own line the rest of the time.
            $isAvecContrat = $quinzaine->enterprise->contract_type === 'avec_contrat';
            $brutDay = $calc['brut'] + $calc['hs_pay'] + ($record->is_jf ? $calc['sal_net_j'] : 0)
                + ($isAvecContrat && !$record->is_jf ? $employee->complement : 0);
            $totalGains += $brutDay;
            $totalRetenues += ($brutDay - $record->net);
        }

        // Same historical-rate reasoning as above: base it on the employee's own last worked
        // day's rate this period, not the enterprise's current rate.
        $baseRateThisPeriod = $records->last()->rate ?? $quinzaine->enterprise->default_brut_rate;
        $standardNetJ = $quinzaine->enterprise->contract_type === 'avec_contrat'
            ? $baseRateThisPeriod * (1 - 0.0674)
            : $baseRateThisPeriod;
        $baseNetJ = $quinzaine->enterprise->contract_type === 'avec_contrat'
            ? $standardNetJ + $employee->complement
            : $standardNetJ;

        $pdf = Pdf::loadView('exports.payslip', [
            'employee' => $employee,
            'quinzaine' => $quinzaine,
            'records' => $records,
            'totalNet' => $totalNet,
            'totalGains' => $totalGains,
            'totalRetenues' => $totalRetenues,
            'baseNetJ' => $baseNetJ
        ]);

        return $pdf->download('Bulletin_' . $employee->matricule . '_' . $quinzaine->label . '.pdf');
    }

    public function history(Request $request)
    {
        $farmId = $this->scopedFarmId($request);
        $enterpriseId = $this->scopedEnterpriseId($request, $farmId);

        if (!$enterpriseId && $request->user()->role !== 'super_admin' && !$farmId) {
            abort(403);
        }

        // Get all quinzaines for this enterprise (or every enterprise in the active farm, if none picked)
        $quinzaines = Quinzaine::query()
            ->when($enterpriseId, fn($q) => $q->where('enterprise_id', $enterpriseId))
            ->when(!$enterpriseId && $farmId, fn($q) => $q->whereHas('enterprise', fn($eq) => $eq->where('farm_id', $farmId)))
            ->orderBy('start_date', 'desc')
            ->get();

        // Each division opens its own Quinzaine row for the same real-world pay period, so without
        // grouping the matrix would show one duplicate (mostly empty) column per division. Merge
        // quinzaines that share the same start/end date into a single period column, labeled
        // "1QZ/2QZ Mois Année" the same way the Pointage export picker and Analytics range picker do.
        $frenchMonths = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];
        $periods = $quinzaines
            ->groupBy(fn($q) => $q->start_date->format('Y-m-d') . '_' . $q->end_date->format('Y-m-d'))
            ->map(function ($group) use ($frenchMonths) {
                $first = $group->first();
                $qzNum = ((int) $first->start_date->format('j')) <= 15 ? '1' : '2';
                return (object) [
                    'key' => $first->start_date->format('Y-m-d') . '_' . $first->end_date->format('Y-m-d'),
                    'label' => $qzNum . 'QZ ' . $frenchMonths[(int) $first->start_date->format('n')] . ' ' . $first->start_date->format('Y'),
                    'start_date' => $first->start_date,
                    'end_date' => $first->end_date,
                    'quinzaine_ids' => $group->pluck('id')->all(),
                ];
            })
            ->sortByDesc('start_date')
            ->values();

        $quinzaineIdToPeriodKey = [];
        foreach ($periods as $period) {
            foreach ($period->quinzaine_ids as $qid) {
                $quinzaineIdToPeriodKey[$qid] = $period->key;
            }
        }

        // Get all employees
        $employees = Employee::query()
            ->when($enterpriseId, fn($q) => $q->where('enterprise_id', $enterpriseId))
            ->when(!$enterpriseId && $farmId, fn($q) => $q->whereHas('enterprise', fn($eq) => $eq->where('farm_id', $farmId)))
            ->with('enterprise')
            ->get();

        // Get total net per employee per quinzaine
        // Use snapshots for closed quinzaines if available
        $closedQuinzaineIds = Quinzaine::query()
            ->when($enterpriseId, fn($q) => $q->where('enterprise_id', $enterpriseId))
            ->when(!$enterpriseId && $farmId, fn($q) => $q->whereHas('enterprise', fn($eq) => $eq->where('farm_id', $farmId)))
            ->where('is_closed', true)
            ->pluck('id');
            
        $snapshots = \App\Models\QuinzaineSummary::whereIn('quinzaine_id', $closedQuinzaineIds)->get();
        
        $historyData = [];
        
        // 1. Fill from snapshots
        foreach ($snapshots as $snap) {
            if (isset($snap->summary_data['employee_nets'])) {
                foreach ($snap->summary_data['employee_nets'] as $empId => $net) {
                    $historyData[$empId][$snap->quinzaine_id] = [
                        'employee_id' => $empId,
                        'quinzaine_id' => $snap->quinzaine_id,
                        'total_net' => $net
                    ];
                }
            }
        }

        // 2. Fill for unclosed or unsnapped quinzaines
        $remainingQuinzaineIds = Quinzaine::query()
            ->when($enterpriseId, fn($q) => $q->where('enterprise_id', $enterpriseId))
            ->when(!$enterpriseId && $farmId, fn($q) => $q->whereHas('enterprise', fn($eq) => $eq->where('farm_id', $farmId)))
            ->whereNotIn('id', $snapshots->pluck('quinzaine_id'))
            ->pluck('id');

        if ($remainingQuinzaineIds->isNotEmpty()) {
            $rawHistory = PointageRecord::query()
                ->whereIn('quinzaine_id', $remainingQuinzaineIds)
                ->select('employee_id', 'quinzaine_id', DB::raw('SUM(net) as total_net'))
                ->groupBy('employee_id', 'quinzaine_id')
                ->get();

            foreach ($rawHistory as $record) {
                $historyData[$record->employee_id][$record->quinzaine_id] = [
                    'employee_id' => $record->employee_id,
                    'quinzaine_id' => $record->quinzaine_id,
                    'total_net' => $record->total_net
                ];
            }
        }

        // Roll each employee's per-quinzaine totals up to the merged period they belong to
        // (an employee only ever has data in their own division's quinzaine, so this is a
        // straight regroup, never a double-count).
        $periodHistoryData = [];
        foreach ($historyData as $empId => $perQuinzaine) {
            foreach ($perQuinzaine as $qid => $data) {
                $periodKey = $quinzaineIdToPeriodKey[$qid] ?? null;
                if (!$periodKey) {
                    continue;
                }
                if (!isset($periodHistoryData[$empId][$periodKey])) {
                    $periodHistoryData[$empId][$periodKey] = [
                        'employee_id' => $empId,
                        'period_key' => $periodKey,
                        'total_net' => 0,
                    ];
                }
                $periodHistoryData[$empId][$periodKey]['total_net'] += $data['total_net'];
            }
        }

        // Convert to indexed arrays for frontend compatibility
        // Use string keys to force JSON object instead of array
        $formattedHistory = [];
        foreach ($periodHistoryData as $empId => $data) {
            $formattedHistory[(string)$empId] = array_values($data);
        }

        return Inertia::render('Payroll/History', [
            'employees' => $employees,
            'periods' => $periods,
            'history' => (object)$formattedHistory,
            'selectedEnterpriseId' => $enterpriseId,
            'allEnterprises' => in_array($request->user()->role, ['super_admin', 'farm_manager']) ? Enterprise::where('farm_id', $farmId)->get() : []
        ]);
    }
}
