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

    /**
     * Same scoping rule grid() already used, extracted so every method that reads or
     * writes a specific Quinzaine by ID enforces it — export/exportAllDivisions/summary/
     * updateCell previously trusted the route/request-bound quinzaine with no ownership
     * check at all, letting any authenticated user read or write another farm's payroll
     * data just by walking quinzaine IDs.
     */
    private function assertQuinzaineInScope($user, Quinzaine $quinzaine): void
    {
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

    public function export(Request $request, $quinzaineId)
    {
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        $this->assertQuinzaineInScope($request->user(), $quinzaine);

        $cleanLabel = str_replace([' ', '/', '\\'], '_', $quinzaine->label ?: 'Pointage');
        $fileName = $cleanLabel . '.xlsx';

        return Excel::download(new PointageExport($quinzaine, $quinzaine->enterprise), $fileName); // Updated to pass Quinzaine and Enterprise objects
    }

    // New method for exporting all divisions
    public function exportAllDivisions(Request $request, Quinzaine $quinzaine)
    {
        $quinzaine->loadMissing('enterprise');
        $this->assertQuinzaineInScope($request->user(), $quinzaine);

        $cleanLabel = str_replace([' ', '/', '\\'], '_', $quinzaine->label ?: 'Pointage');
        $fileName = 'All_Divisions_' . $cleanLabel . '.xlsx';

        return Excel::download(new DivisionsPointageExport($quinzaine), $fileName);
    }

    /**
     * /pointage — the zone's landing page: a grid of divisions (mirrors
     * Admin/FarmDashboard's "Divisions de la Ferme" cards), each with general info
     * (workers, periods) and a link into that division's quinzaine list. The actual
     * quinzaine list/management lives at quinzaines() below.
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();

        $withCounts = [
            'employees',
            'quinzaines',
            'quinzaines as open_quinzaines_count' => fn ($q) => $q->where('is_closed', false),
        ];

        if ($user->role === 'super_admin') {
            $enterprises = \App\Models\Enterprise::where('farm_id', session('active_farm_id'))
                ->withCount($withCounts)
                ->get();
        } elseif ($user->role === 'farm_manager' || ($user->role === 'data_entry' && !$user->enterprise_id && $user->farm_id)) {
            $enterprises = \App\Models\Enterprise::where('farm_id', $user->farm_id)
                ->withCount($withCounts)
                ->get();
        } elseif ($user->enterprise_id) {
            // Single-enterprise user: still rendered as a (one-card) grid, for consistency.
            $enterprises = \App\Models\Enterprise::where('id', $user->enterprise_id)
                ->withCount($withCounts)
                ->get();
        } else {
            $enterprises = collect();
        }

        return Inertia::render('Pointage/Index', [
            'enterprises' => $enterprises,
        ]);
    }

    public function quinzaines(Request $request)
    {
        $user = $request->user();
        $enterpriseId = $user->role === 'super_admin'
            ? $request->query('enterprise_id')
            : $user->enterprise_id;

        $query = Quinzaine::with('enterprise')
            ->latest();

        if ($user->role === 'super_admin') {
            $query->whereHas('enterprise', function ($q) {
                $q->where('farm_id', session('active_farm_id'));
            });
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
                    return Inertia::render('Pointage/Quinzaines', [
                        'quinzaines' => [],
                        'enterprises' => [],
                        'error' => 'Aucune division ne vous est assignée.'
                    ]);
                }
            } else {
                $query->where('enterprise_id', $enterpriseId);
            }
        }

        return Inertia::render('Pointage/Quinzaines', [
            'quinzaines' => $query->get(),
            'enterprises' => $user->role === 'super_admin'
                ? \App\Models\Enterprise::where('farm_id', session('active_farm_id'))->get()
                : (($user->role === 'farm_manager' || ($user->role === 'data_entry' && !$user->enterprise_id))
                    ? \App\Models\Enterprise::where('farm_id', $user->farm_id)->get()
                    : [])
        ]);
    }

    public function grid(Request $request, $quinzaineId)
    {
        $user = $request->user();
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        $this->assertQuinzaineInScope($user, $quinzaine);

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

        // Fetch existing records for this quinzaine, filtering to only those within the date range
        $records = PointageRecord::where('quinzaine_id', $quinzaineId)
            ->whereDate('date', '>=', $quinzaine->start_date)
            ->whereDate('date', '<=', $quinzaine->end_date)
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
            'quantity' => 'nullable|numeric|min:0',
            'is_jf' => 'nullable|boolean',
        ]);

        $quinzaine = Quinzaine::with('enterprise')->findOrFail($validated['quinzaine_id']);
        $this->assertQuinzaineInScope($request->user(), $quinzaine);

        // employee_id was only checked with exists:employees,id — an employee from a
        // completely different enterprise/farm could otherwise be written into this
        // quinzaine (mismatched data, and a cross-tenant write via an ID the caller
        // isn't actually scoped to).
        $employee = Employee::findOrFail($validated['employee_id']);
        abort_unless($employee->enterprise_id === $quinzaine->enterprise_id, 403);

        if ($quinzaine->is_closed) {
            return redirect()->back()->withErrors(['date' => 'Cette période est clôturée et ne peut plus être modifiée.']);
        }

        // No operation/bloc, and not a paid holiday either -> plain absence, no pay: delete
        // the record. If is_jf IS set though, this is a public holiday the employee did NOT
        // work — still owed one day's pay, just never the worked+JF double (see the branch
        // below and PayrollService::calculate()'s $worked param) — so it falls through to be
        // saved as a record instead of deleted.
        if ((!$validated['operation_id'] || !$validated['bloc_id']) && empty($validated['is_jf'])) {
            PointageRecord::where('employee_id', $validated['employee_id'])
                ->where('quinzaine_id', $validated['quinzaine_id'])
                ->whereDate('date', $validated['date'])
                ->delete();
            return redirect()->back();
        }

        if (!$validated['operation_id'] || !$validated['bloc_id']) {
            $calc = $this->payrollService->calculate(
                $quinzaine->enterprise->contract_type,
                $quinzaine->enterprise->default_brut_rate,
                0,
                $employee->complement,
                true,
                $quinzaine->enterprise->invoiced_to_client,
                false
            );

            // Delete any existing records for this employee/quinzaine/date to ensure only one record per day
            PointageRecord::where('employee_id', $validated['employee_id'])
                ->where('quinzaine_id', $validated['quinzaine_id'])
                ->whereDate('date', Carbon::parse($validated['date'])->format('Y-m-d'))
                ->delete();

            // Create a new record
            PointageRecord::create([
                'employee_id' => $validated['employee_id'],
                'quinzaine_id' => $validated['quinzaine_id'],
                'date' => Carbon::parse($validated['date'])->format('Y-m-d'),
                'operation_id' => null,
                'bloc_id' => null,
                'hours' => 0,
                'quantity' => null,
                'is_jf' => true,
                'rate' => $quinzaine->enterprise->default_brut_rate,
                'brut' => $calc['brut'],
                'net' => $calc['total_net'],
            ]);

            return redirect()->back();
        }

        $operation = Operation::find($validated['operation_id']);
        $quantity = $validated['quantity'] ?? null;

        if ($operation->unit_rate && $quantity > 0) {
            // Piece-rate: pay = quantity * the operation's own rate, independent of the
            // enterprise's rate/employee's complement — no HS/JF concept for piece-rate work.

            // Delete any existing records for this employee/quinzaine/date to ensure only one record per day
            PointageRecord::where('employee_id', $validated['employee_id'])
                ->where('quinzaine_id', $validated['quinzaine_id'])
                ->whereDate('date', Carbon::parse($validated['date'])->format('Y-m-d'))
                ->delete();

            // Create a new record
            PointageRecord::create([
                'employee_id' => $validated['employee_id'],
                'quinzaine_id' => $validated['quinzaine_id'],
                'date' => Carbon::parse($validated['date'])->format('Y-m-d'),
                'operation_id' => $validated['operation_id'],
                'bloc_id' => $validated['bloc_id'],
                'hours' => 0,
                'quantity' => $quantity,
                'is_jf' => false,
                'rate' => $operation->unit_rate,
                'brut' => $operation->unit_rate,
                'net' => $quantity * $operation->unit_rate,
            ]);

            return redirect()->back();
        }

        $hs = $validated['hours'] ?? 0;
        $isJf = $validated['is_jf'] ?? false;

        $calc = $this->payrollService->calculate(
            $quinzaine->enterprise->contract_type,
            $quinzaine->enterprise->default_brut_rate,
            $hs,
            $employee->complement,
            $isJf,
            $quinzaine->enterprise->invoiced_to_client
        );

        // Delete any existing records for this employee/quinzaine/date to ensure only one record per day
        PointageRecord::where('employee_id', $validated['employee_id'])
            ->where('quinzaine_id', $validated['quinzaine_id'])
            ->whereDate('date', Carbon::parse($validated['date'])->format('Y-m-d'))
            ->delete();

        // Create a new record
        PointageRecord::create([
            'employee_id' => $validated['employee_id'],
            'quinzaine_id' => $validated['quinzaine_id'],
            'date' => Carbon::parse($validated['date'])->format('Y-m-d'),
            'operation_id' => $validated['operation_id'],
            'bloc_id' => $validated['bloc_id'],
            'hours' => $hs,
            'quantity' => null,
            'is_jf' => $isJf,
            'rate' => $quinzaine->enterprise->default_brut_rate,
            'brut' => $calc['brut'],
            'net' => $calc['total_net'],
        ]);

        return back();
    }

    /**
     * "Coller sur les jours restants" — applies one already-entered day's Operation+Bloc to
     * several other dates for the same employee in a single request, for the common case of an
     * ouvrier doing the same job every day of the quinzaine. Deliberately narrower than
     * updateCell(): no piece-rate (a copied quantity would be meaningless — each day's quantity
     * is real work, not something to duplicate), no per-day H.S., no JF — those still go through
     * the single-cell modal so they're entered deliberately, not accidentally propagated.
     * Also supports clearing days when operation_id and bloc_id are null.
     */
    public function updateCellBulk(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'quinzaine_id' => 'required|exists:quinzaines,id',
            'operation_id' => 'nullable|exists:operations,id',
            'bloc_id' => 'nullable|exists:blocs,id',
            'dates' => 'required|array|min:1',
            'dates.*' => 'date',
            'hours' => 'nullable|numeric|min:0',
            'is_jf' => 'nullable|boolean',
        ]);

        $quinzaine = Quinzaine::with('enterprise')->findOrFail($validated['quinzaine_id']);
        $this->assertQuinzaineInScope($request->user(), $quinzaine);

        $employee = Employee::findOrFail($validated['employee_id']);
        abort_unless($employee->enterprise_id === $quinzaine->enterprise_id, 403);

        if ($quinzaine->is_closed) {
            return redirect()->back()->withErrors(['date' => 'Cette période est clôturée et ne peut plus être modifiée.']);
        }

        // If operation_id is null, this is a clear operation - delete all records for the dates
        if (!$validated['operation_id'] || !$validated['bloc_id']) {
            foreach ($validated['dates'] as $date) {
                PointageRecord::where('employee_id', $employee->id)
                    ->where('quinzaine_id', $quinzaine->id)
                    ->whereDate('date', Carbon::parse($date)->format('Y-m-d'))
                    ->delete();
            }
            return back();
        }

        $operation = Operation::find($validated['operation_id']);
        abort_if($operation->unit_rate, 422, 'Impossible de coller une opération à la quantité sur plusieurs jours — chaque jour a sa propre quantité.');

        $calc = $this->payrollService->calculate(
            $quinzaine->enterprise->contract_type,
            $quinzaine->enterprise->default_brut_rate,
            0,
            $employee->complement,
            false,
            $quinzaine->enterprise->invoiced_to_client
        );

        foreach ($validated['dates'] as $date) {
            PointageRecord::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'quinzaine_id' => $quinzaine->id,
                    'date' => Carbon::parse($date)->format('Y-m-d'),
                ],
                [
                    'operation_id' => $validated['operation_id'],
                    'bloc_id' => $validated['bloc_id'],
                    'hours' => 0,
                    'quantity' => null,
                    'is_jf' => false,
                    'rate' => $quinzaine->enterprise->default_brut_rate,
                    'brut' => $calc['brut'],
                    'net' => $calc['total_net'],
                ]
            );
        }

        return redirect()->back();
    }

    public function summary(Request $request, $quinzaineId)
    {
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        $this->assertQuinzaineInScope($request->user(), $quinzaine);

        // Inner-joining operations/blocs would silently drop paid-holiday records that have no
        // operation/bloc (see PointageController::updateCell()) from dailyTotals — left join so
        // their net still counts toward the total, they just can't be placed in the matrix below.
        $records = PointageRecord::where('quinzaine_id', $quinzaineId)
            ->leftJoin('operations', 'pointage_records.operation_id', '=', 'operations.id')
            ->leftJoin('blocs', 'pointage_records.bloc_id', '=', 'blocs.id')
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
            // Skip records that fall outside the current date range (e.g., after editing quinzaine dates)
            $dateKey = $record->date->format('Y-m-d');
            if (!in_array($dateKey, $days)) {
                continue;
            }

            // Read the record's own stored net directly rather than recomputing via
            // calculate() — piece-rate records (quantity set) were computed from the
            // Operation's unit_rate, not the enterprise's rate/employee's complement, so
            // recomputing here would silently show the wrong total for them.
            // A record with no operation/bloc (unworked paid holiday) has nowhere to sit in the
            // matrix, but its net must still count toward the day's total.
            if ($record->bloc_name !== null && $record->op_name !== null) {
                $blocMatrices[$record->bloc_name][$record->op_name][$dateKey] += $record->net;
            }
            $dailyTotals[$dateKey] += $record->net;
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
