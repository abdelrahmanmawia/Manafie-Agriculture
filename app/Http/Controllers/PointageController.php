<?php

namespace App\Http\Controllers;

use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\Employee;
use App\Models\Operation;
use App\Models\Bloc;
use App\Models\Parcelle;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\PointageExport;
use App\Exports\DivisionsPointageExport; // Add this import

class PointageController extends Controller
{
    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    public function export($quinzaineId)
    {
        $quinzaine = Quinzaine::findOrFail($quinzaineId);
        $cleanLabel = str_replace([' ', '/', '\\'], '_', $quinzaine->label ?: 'Pointage');
        $fileName = $cleanLabel . '.xlsx';

        return Excel::download(new PointageExport($quinzaine, $quinzaine->enterprise), $fileName); // Updated to pass Quinzaine and Enterprise objects
    }

    // New method for exporting all divisions
    public function exportAllDivisions(Quinzaine $quinzaine)
    {
        $cleanLabel = str_replace([' ', '/', '\\'], '_', $quinzaine->label ?: 'Pointage');
        $fileName = 'All_Divisions_' . $cleanLabel . '.xlsx';

        return Excel::download(new DivisionsPointageExport($quinzaine), $fileName);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $enterpriseId = $user->role === 'super_admin'
            ? $request->query('enterprise_id')
            : $user->enterprise_id;

        $query = Quinzaine::with('enterprise')
            ->latest();

        if ($user->role === 'super_admin') {
            if ($enterpriseId) {
                $query->where('enterprise_id', $enterpriseId);
            }
        } elseif ($user->role === 'farm_manager') {
            $query->whereHas('enterprise', function($q) use ($user) {
                $q->where('farm_id', $user->farm_id);
            });
            if ($enterpriseId) {
                $query->where('enterprise_id', $enterpriseId);
            }
        } else {
            // Data entry or others
            if (!$enterpriseId) {
                if ($user->farm_id) {
                    $query->whereHas('enterprise', function($q) use ($user) {
                        $q->where('farm_id', $user->farm_id);
                    });
                } else {
                    return Inertia::render('Pointage/Index', [
                        'quinzaines' => [],
                        'enterprises' => [],
                        'error' => 'Aucune division ne vous est assignée.'
                    ]);
                }
            } else {
                $query->where('enterprise_id', $enterpriseId);
            }
        }

        return Inertia::render('Pointage/Index', [
            'quinzaines' => $query->get(),
            'enterprises' => $user->role === 'super_admin'
                ? \App\Models\Enterprise::all()
                : (($user->role === 'farm_manager' || ($user->role === 'data_entry' && !$user->enterprise_id))
                    ? \App\Models\Enterprise::where('farm_id', $user->farm_id)->get()
                    : [])
        ]);
    }

    public function grid(Request $request, $quinzaineId)
    {
        $user = $request->user();
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);

        // Security check
        if ($user->enterprise_id) {
            if ($quinzaine->enterprise_id !== $user->enterprise_id) abort(403);
        } elseif ($user->farm_id) {
            if ($quinzaine->enterprise->farm_id !== $user->farm_id) abort(403);
        } elseif ($user->role !== 'super_admin') {
            abort(403);
        }

        $enterpriseId = $quinzaine->enterprise_id;
        $employees = Employee::where('enterprise_id', $enterpriseId)->where('is_active', true)->get();
        $operations = Operation::where('farm_id', $quinzaine->enterprise->farm_id)->get();
        $blocs = Bloc::where('farm_id', $quinzaine->enterprise->farm_id)->get();

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
            'is_jf' => 'nullable|boolean',
        ]);

        $quinzaine = Quinzaine::with('enterprise')->findOrFail($validated['quinzaine_id']);

        if ($quinzaine->is_closed) {
            return redirect()->back()->with('error', 'This period is closed and cannot be modified.');
        }

        // If operation or bloc is empty, delete the record (mark as absent)
        if (!$validated['operation_id'] || !$validated['bloc_id']) {
            PointageRecord::where('employee_id', $validated['employee_id'])
                ->where('quinzaine_id', $validated['quinzaine_id'])
                ->whereDate('date', $validated['date'])
                ->delete();
            return redirect()->back();
        }

        $employee = Employee::find($validated['employee_id']);
        $hs = $validated['hours'] ?? 0;
        $isJf = $validated['is_jf'] ?? false;

        $calc = $this->payrollService->calculate(
            $quinzaine->enterprise->contract_type,
            $quinzaine->enterprise->default_brut_rate,
            $hs,
            $employee->complement,
            $isJf
        );

        PointageRecord::updateOrCreate(
            [
                'employee_id' => $validated['employee_id'],
                'quinzaine_id' => $validated['quinzaine_id'],
                'date' => Carbon::parse($validated['date'])->format('Y-m-d')
            ],
            [
                'operation_id' => $validated['operation_id'],
                'bloc_id' => $validated['bloc_id'],
                'hours' => $hs,
                'is_jf' => $isJf,
                'rate' => $quinzaine->enterprise->default_brut_rate,
                'brut' => $calc['brut'],
                'net' => $calc['total_net'],
            ]
        );

        return redirect()->back();
    }

    public function summary($quinzaineId)
    {
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);

        $records = PointageRecord::where('quinzaine_id', $quinzaineId)
            ->join('operations', 'pointage_records.operation_id', '=', 'operations.id')
            ->join('blocs', 'pointage_records.bloc_id', '=', 'blocs.id')
            ->join('employees', 'pointage_records.employee_id', '=', 'employees.id')
            ->select(
                'pointage_records.*',
                'operations.name as op_name',
                'blocs.name as bloc_name',
                'employees.complement'
            )
            ->get();

        $allBlocs = Bloc::where('farm_id', $quinzaine->enterprise->farm_id)->pluck('name')->toArray();
        $allOps = Operation::where('farm_id', $quinzaine->enterprise->farm_id)->pluck('name')->toArray();

        // Generate date range
        $period = \Carbon\CarbonPeriod::create($quinzaine->start_date, $quinzaine->end_date);
        $days = [];
        foreach ($period as $date) { $days[] = $date->format('Y-m-d'); }

        $blocMatrices = [];
        foreach ($allBlocs as $bloc) {
            $blocMatrices[$bloc] = [];
            foreach ($allOps as $op) {
                $blocMatrices[$bloc][$op] = [];
                foreach ($days as $day) {
                    $blocMatrices[$bloc][$op][$day] = 0;
                }
            }
        }

        $dailyTotals = array_fill_keys($days, 0);

        foreach ($records as $record) {
            $calc = $this->payrollService->calculate(
                $quinzaine->enterprise->contract_type,
                $quinzaine->enterprise->default_brut_rate,
                $record->hours,
                $record->complement,
                $record->is_jf
            );

            $dateKey = $record->date->format('Y-m-d');
            // Use worker net pay as requested
            $blocMatrices[$record->bloc_name][$record->op_name][$dateKey] += $calc['total_net'];
            $dailyTotals[$dateKey] += $calc['total_net'];
        }

        return response()->json([
            'bloc_matrices' => $blocMatrices,
            'blocs' => $allBlocs,
            'operations' => $allOps,
            'days' => $days,
            'daily_totals' => $dailyTotals
        ]);
    }
}
