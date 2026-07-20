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

    public function downloadGeneralPayslip($quinzaineId)
    {
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
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

    public function downloadPayslip($employeeId, $quinzaineId)
    {
        $employee = Employee::findOrFail($employeeId);
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
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
            $calc = $this->payrollService->calculate(
                $quinzaine->enterprise->contract_type,
                $quinzaine->enterprise->default_brut_rate,
                $record->hours,
                $employee->complement,
                $record->is_jf
            );
            $totalNet += $calc['total_net'];
            
            // Simplified Gain/Deduction math for the PDF view
            $brutDay = $calc['brut'] + ($record->hours * 11.36) + ($record->is_jf ? $calc['sal_net_j'] : 0) + $employee->complement;
            $totalGains += $brutDay;
            $totalRetenues += ($brutDay - $calc['total_net']);
        }

        $baseNetJ = ($quinzaine->enterprise->default_brut_rate * (1 - 0.0674)) + $employee->complement;

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
        $enterpriseId = $request->user()->enterprise_id ?? $request->query('enterprise_id');
        $farmId = $request->user()->role === 'super_admin' ? session('active_farm_id') : $request->user()->farm_id;

        if (!$enterpriseId && $request->user()->role !== 'super_admin' && !$farmId) {
            abort(403);
        }

        // Get all quinzaines for this enterprise (or every enterprise in the active farm, if none picked)
        $quinzaines = Quinzaine::query()
            ->when($enterpriseId, fn($q) => $q->where('enterprise_id', $enterpriseId))
            ->when(!$enterpriseId && $farmId, fn($q) => $q->whereHas('enterprise', fn($eq) => $eq->where('farm_id', $farmId)))
            ->orderBy('start_date', 'desc')
            ->get();

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

        // Convert to indexed arrays for frontend compatibility
        // Use string keys to force JSON object instead of array
        $formattedHistory = [];
        foreach ($historyData as $empId => $data) {
            $formattedHistory[(string)$empId] = array_values($data);
        }

        return Inertia::render('Payroll/History', [
            'employees' => $employees,
            'quinzaines' => $quinzaines,
            'history' => (object)$formattedHistory,
            'selectedEnterpriseId' => $enterpriseId,
            'allEnterprises' => $request->user()->role === 'super_admin' ? Enterprise::where('farm_id', $farmId)->get() : []
        ]);
    }
}
