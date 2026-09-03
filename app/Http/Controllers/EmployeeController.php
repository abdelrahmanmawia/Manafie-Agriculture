<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    /**
     * Employees are Pointage-domain master data: farm_manager (or super_admin working the
     * active farm) may create/delete/toggle, as can a data_entry granted Pointage access
     * (canAccessPointage()) — never a stock-only data_entry. Also locked to its own enterprise
     * when it has one.
     */
    private function assertEmployeeManagerAccess(Request $request, int $farmId): void
    {
        $user = $request->user();
        abort_unless($user->canAccessPointage(), 403);

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

        abort_unless($farmId && $employee->farm_id === (int) $farmId, 403);

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

        // Employee is farm-scoped (the same real person can have pointage under several
        // enterprises of the farm over time); enterprise_id here only narrows which division's
        // employees to show, it's never the scoping boundary itself.
        $query = Employee::with('enterprise');

        if ($user->role === 'super_admin') {
            $query->where('farm_id', session('active_farm_id'));
            if ($enterpriseId) {
                $query->where('enterprise_id', $enterpriseId);
            }
        } elseif ($user->role === 'farm_manager') {
            $query->where('farm_id', $user->farm_id);
            if ($enterpriseId) {
                $query->where('enterprise_id', $enterpriseId);
            }
        } elseif ($enterpriseId) {
            $query->where('enterprise_id', $enterpriseId);
        } elseif ($user->farm_id) {
            $query->where('farm_id', $user->farm_id);
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
        $this->assertEnterpriseAssignable($request, (int) $request->enterprise_id);
        abort_unless($request->user()->canAccessPointage(), 403);

        $farmId = \App\Models\Enterprise::findOrFail($request->enterprise_id)->farm_id;

        $validated = $request->validate([
            // matricule is only unique within a farm — the same real person keeps ONE Employee
            // row as they move between the farm's divisions over time.
            'matricule' => ['required', 'string', Rule::unique('employees')->where(
                fn ($query) => $query->where('farm_id', $farmId)
            )],
            // CIN is the real dedup identity (matches the DB's unique(farm_id, cin)) — validated
            // here too so a collision surfaces as a normal form error, not a raw DB exception.
            'cin' => ['nullable', 'string', 'max:50', Rule::unique('employees')->where(
                fn ($query) => $query->where('farm_id', $farmId)
            )],
            'full_name' => 'required|string|max:255',
            'cnss_number' => 'nullable|string|max:50',
            'dob' => 'nullable|date',
            'hire_date' => 'nullable|date',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:100',
            'rib' => 'nullable|string|max:100',
            'base_rate' => 'required|numeric|min:0',
            'enterprise_id' => 'required|exists:enterprises,id'
        ]);

        Employee::create(array_merge($validated, [
            'farm_id' => $farmId,
            'is_active' => true
        ]));

        return redirect()->back();
    }

    public function update(Request $request, Employee $employee)
    {
        $this->assertEmployeeInScope($request, $employee);

        $validated = $request->validate([
            // Employee's farm_id is fixed (set once at creation) — matricule/CIN uniqueness is
            // scoped to it, not to whichever enterprise they're being reassigned to.
            'matricule' => ['required', 'string', Rule::unique('employees')->where(
                fn ($query) => $query->where('farm_id', $employee->farm_id)
            )->ignore($employee->id)],
            'full_name' => 'required|string|max:255',
            'cin' => ['nullable', 'string', 'max:50', Rule::unique('employees')->where(
                fn ($query) => $query->where('farm_id', $employee->farm_id)
            )->ignore($employee->id)],
            'cnss_number' => 'nullable|string|max:50',
            'dob' => 'nullable|date',
            'hire_date' => 'nullable|date',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:100',
            'rib' => 'nullable|string|max:100',
            'base_rate' => 'required|numeric|min:0',
            'complement' => 'nullable|numeric|min:0',
            'enterprise_id' => 'required|exists:enterprises,id',
            // Sent as a real JS boolean on a plain Inertia PUT, but as the literal string
            // "true"/"false" once a photo file forces the request into multipart/FormData —
            // Laravel's `boolean` rule strictly rejects those strings (only true/false/0/1/'0'/'1'),
            // so accept them here and coerce below via $request->boolean() rather than trusting
            // the raw validated value (casting the STRING "false" with PHP's (bool) is true).
            'is_active' => ['sometimes', Rule::in([true, false, 0, 1, '0', '1', 'true', 'false'])],
            'photo' => 'nullable|image|max:5120',
        ]);

        if (array_key_exists('is_active', $validated)) {
            $validated['is_active'] = $request->boolean('is_active');
        }

        $this->assertEnterpriseAssignable($request, (int) $validated['enterprise_id']);

        if ($request->hasFile('photo')) {
            if ($employee->photo_path) {
                Storage::disk('public')->delete($employee->photo_path);
            }
            $validated['photo_path'] = $request->file('photo')->store('badges', 'public');
        }
        unset($validated['photo']);

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
        $this->assertEmployeeManagerAccess($request, $employee->farm_id);

        $employee->update(['is_active' => !$employee->is_active]);
        return redirect()->back();
    }

    public function destroy(Request $request, Employee $employee)
    {
        $this->assertEmployeeManagerAccess($request, $employee->farm_id);

        $employee->delete();
        return redirect()->back();
    }
}
