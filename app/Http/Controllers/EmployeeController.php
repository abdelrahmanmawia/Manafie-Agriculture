<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    /**
     * Employees are enterprise-owned master data, same access tier as Stock's
     * Product/Vehicle: farm_manager (or super_admin working the active farm) may
     * create/delete/toggle; data_entry never can, and is additionally locked to
     * its own enterprise when it has one.
     */
    private function assertEmployeeManagerAccess(Request $request, int $farmId): void
    {
        $user = $request->user();
        abort_if($user->role === 'data_entry', 403);

        if ($user->role === 'super_admin') {
            abort_unless((int) session('active_farm_id') === $farmId, 403);
            return;
        }

        abort_unless($user->farm_id === $farmId, 403);
    }

    /**
     * Looser check used by update(), which data_entry is allowed to call: still requires
     * the employee's farm to match the actor's scope, and further locks an enterprise-scoped
     * data_entry to their own enterprise_id.
     */
    private function assertEmployeeInScope(Request $request, Employee $employee): void
    {
        $user = $request->user();
        $farmId = $user->role === 'super_admin' ? session('active_farm_id') : $user->farm_id;

        abort_unless($farmId && $employee->enterprise->farm_id === (int) $farmId, 403);

        if ($user->enterprise_id) {
            abort_unless($employee->enterprise_id === $user->enterprise_id, 403);
        }
    }

    /**
     * Validates that $enterpriseId is one the current actor is allowed to assign an
     * employee to (used by store()/update() to stop a cross-farm enterprise_id in the payload).
     */
    private function assertEnterpriseAssignable(Request $request, int $enterpriseId): void
    {
        $user = $request->user();
        $farmId = $user->role === 'super_admin' ? session('active_farm_id') : $user->farm_id;

        $enterprise = \App\Models\Enterprise::find($enterpriseId);
        abort_unless($enterprise && $farmId && $enterprise->farm_id === (int) $farmId, 403);

        if ($user->enterprise_id) {
            abort_unless($enterpriseId === $user->enterprise_id, 403);
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $enterpriseId = $user->enterprise_id ?? $request->query('enterprise_id');
        $searchQuery = $request->query('search');

        $query = Employee::with('enterprise');

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
        } elseif ($enterpriseId) {
            $query->where('enterprise_id', $enterpriseId);
        } elseif ($user->farm_id) {
            $query->whereHas('enterprise', function($q) use ($user) {
                $q->where('farm_id', $user->farm_id);
            });
        }

        if ($searchQuery) {
            $query->where(function($q) use ($searchQuery) {
                $q->where('matricule', 'like', '%' . $searchQuery . '%')
                  ->orWhere('full_name', 'like', '%' . $searchQuery . '%')
                  ->orWhere('cin', 'like', '%' . $searchQuery . '%')
                  ->orWhere('phone', 'like', '%' . $searchQuery . '%');
            });
        }

        $enterprises = $user->role === 'super_admin'
            ? \App\Models\Enterprise::where('farm_id', session('active_farm_id'))->get()
            : (($user->role === 'farm_manager' || ($user->role === 'data_entry' && !$user->enterprise_id))
                ? \App\Models\Enterprise::where('farm_id', $user->farm_id)->get()
                : []);

        return Inertia::render('Admin/Employees', [
            'employees' => $query->get(),
            'enterprises' => $enterprises,
            'selectedEnterpriseId' => $enterpriseId,
            'searchQuery' => $searchQuery
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'matricule' => 'required|string|unique:employees,matricule',
            'full_name' => 'required|string|max:255',
            'cin' => 'nullable|string|max:50',
            'cnss_number' => 'nullable|string|max:50',
            'dob' => 'nullable|date',
            'hire_date' => 'nullable|date',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:100',
            'rib' => 'nullable|string|max:100',
            'type' => 'required|in:hafila,persea,interim',
            'base_rate' => 'required|numeric|min:0',
            'enterprise_id' => 'required|exists:enterprises,id'
        ]);

        $this->assertEnterpriseAssignable($request, (int) $validated['enterprise_id']);
        abort_if($request->user()->role === 'data_entry', 403);

        Employee::create(array_merge($validated, [
            'is_active' => true
        ]));

        return redirect()->back();
    }

    public function update(Request $request, Employee $employee)
    {
        $this->assertEmployeeInScope($request, $employee);

        $validated = $request->validate([
            'matricule' => 'required|string|unique:employees,matricule,' . $employee->id,
            'full_name' => 'required|string|max:255',
            'cin' => 'nullable|string|max:50',
            'cnss_number' => 'nullable|string|max:50',
            'dob' => 'nullable|date',
            'hire_date' => 'nullable|date',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:100',
            'rib' => 'nullable|string|max:100',
            'type' => 'required|in:hafila,persea,interim',
            'base_rate' => 'required|numeric|min:0',
            'complement' => 'nullable|numeric|min:0',
            'enterprise_id' => 'required|exists:enterprises,id',
            'is_active' => 'boolean'
        ]);

        $this->assertEnterpriseAssignable($request, (int) $validated['enterprise_id']);

        $employee->update($validated);

        // Keep this employee's still-open pointage in sync with the new complement — otherwise
        // their stored net/brut stay priced at whatever complement was in effect when each cell
        // was entered, silently diverging from what a payslip would compute for the same day.
        if ($employee->wasChanged('complement')) {
            $this->payrollService->recalculateOpenRecordsForEmployee($employee);
        }

        return redirect()->back();
    }

    public function toggleActive(Request $request, Employee $employee)
    {
        $this->assertEmployeeManagerAccess($request, $employee->enterprise->farm_id);

        $employee->update(['is_active' => !$employee->is_active]);
        return redirect()->back();
    }

    public function destroy(Request $request, Employee $employee)
    {
        $this->assertEmployeeManagerAccess($request, $employee->enterprise->farm_id);

        $employee->delete();
        return redirect()->back();
    }
}
