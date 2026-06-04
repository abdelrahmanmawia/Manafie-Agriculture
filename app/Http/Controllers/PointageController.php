<?php

namespace App\Http\Controllers;

use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\Employee;
use App\Models\Operation;
use App\Models\Bloc;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class PointageController extends Controller
{
    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    public function index(Request $request)
    {
        $enterpriseId = $request->user()->enterprise_id;
        
        $query = Quinzaine::with('enterprise')
            ->latest();

        if ($enterpriseId) {
            $query->where('enterprise_id', $enterpriseId);
        }
        
        return Inertia::render('Pointage/Index', [
            'quinzaines' => $query->get(),
            'enterprises' => $request->user()->role === 'super_admin' ? \App\Models\Enterprise::all() : []
        ]);
    }

    public function grid(Request $request, $quinzaineId)
    {
        $userEnterpriseId = $request->user()->enterprise_id;
        
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        
        // Safety check for regular enterprise admins
        if ($userEnterpriseId && $quinzaine->enterprise_id !== $userEnterpriseId) {
            abort(403);
        }

        $enterpriseId = $quinzaine->enterprise_id;
        $employees = Employee::where('enterprise_id', $enterpriseId)->get();
        $operations = Operation::where('enterprise_id', $enterpriseId)->get();
        $blocs = Bloc::where('enterprise_id', $enterpriseId)->get();

        // Generate the 15-16 days period
        $period = CarbonPeriod::create($quinzaine->start_date, $quinzaine->end_date);
        $days = [];
        foreach ($period as $date) {
            $days[] = $date->format('Y-m-d');
        }

        // Fetch existing records for this quinzaine
        $records = PointageRecord::where('quinzaine_id', $quinzaineId)
            ->get()
            ->groupBy(['employee_id', function ($item) {
                return $item->date->format('Y-m-d');
            }]);

        return Inertia::render('Pointage/Grid', [
            'quinzaine' => $quinzaine,
            'employees' => $employees,
            'operations' => $operations,
            'blocs' => $blocs,
            'days' => $days,
            'existingRecords' => $records
        ]);
    }

    public function updateCell(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'quinzaine_id' => 'required|exists:quinzaines,id',
            'operation_id' => 'nullable|exists:operations,id',
            'bloc_id' => 'nullable|exists:blocs,id',
            'date' => 'required|date',
            'hours' => 'nullable|numeric|min:0',
        ]);

        $quinzaine = Quinzaine::with('enterprise')->findOrFail($validated['quinzaine_id']);
        
        if ($quinzaine->is_closed) {
            return redirect()->back()->with('error', 'This period is closed and cannot be modified.');
        }

        // If operation or bloc is empty, delete the record (mark as absent)
        if (!$validated['operation_id'] || !$validated['bloc_id']) {
            PointageRecord::where([
                'employee_id' => $validated['employee_id'],
                'quinzaine_id' => $validated['quinzaine_id'],
                'date' => $validated['date']
            ])->delete();
            return redirect()->back();
        }

        $employee = Employee::find($validated['employee_id']);
        $hs = $validated['hours'] ?? 0; // Treated as H.S
        
        $calc = $this->payrollService->calculate(
            $quinzaine->enterprise->contract_type, // Forced by Enterprise
            $employee->base_rate,
            $hs
        );

        PointageRecord::updateOrCreate(
            [
                'employee_id' => $validated['employee_id'],
                'quinzaine_id' => $validated['quinzaine_id'],
                'date' => $validated['date']
            ],
            array_merge($validated, $calc, [
                'hours' => $hs,
                'rate' => $employee->base_rate
            ])
        );

        return redirect()->back();
    }

    public function summary($quinzaineId)
    {
        $summary = DB::table('pointage_records')
            ->join('operations', 'pointage_records.operation_id', '=', 'operations.id')
            ->join('blocs', 'pointage_records.bloc_id', '=', 'blocs.id')
            ->where('quinzaine_id', $quinzaineId)
            ->select(
                'operations.name as operation',
                'blocs.name as bloc',
                DB::raw('SUM(hours) as total_hours'),
                DB::raw('SUM(brut) as total_brut'),
                DB::raw('SUM(net) as total_net'),
                DB::raw('COUNT(DISTINCT employee_id) as worker_count')
            )
            ->groupBy('operations.name', 'blocs.name')
            ->get();

        return response()->json($summary);
    }
}
