<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeController extends Controller
{
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

        Employee::create(array_merge($validated, [
            'is_active' => true
        ]));

        return redirect()->back();
    }

    public function update(Request $request, Employee $employee)
    {
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

        $employee->update($validated);

        return redirect()->back();
    }

    public function toggleActive(Employee $employee)
    {
        $employee->update(['is_active' => !$employee->is_active]);
        return redirect()->back();
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return redirect()->back();
    }
}
