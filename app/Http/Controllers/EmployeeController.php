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
        
        $query = Employee::with('enterprise');

        if ($enterpriseId) {
            $query->where('enterprise_id', $enterpriseId);
        } elseif ($user->farm_id && $user->role !== 'super_admin') {
            $query->whereHas('enterprise', function($q) use ($user) {
                $q->where('farm_id', $user->farm_id);
            });
        }

        return Inertia::render('Admin/Employees', [
            'employees' => $query->get(),
            'enterprises' => $user->role === 'super_admin' 
                ? \App\Models\Enterprise::all() 
                : (($user->role === 'farm_manager' || ($user->role === 'data_entry' && !$user->enterprise_id)) 
                    ? \App\Models\Enterprise::where('farm_id', $user->farm_id)->get() 
                    : []),
            'selectedEnterpriseId' => $enterpriseId
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
            'enterprise_id' => 'required_if:user_role,super_admin|exists:enterprises,id'
        ]);

        $enterpriseId = $request->user()->enterprise_id ?? $request->enterprise_id;

        Employee::create(array_merge($validated, [
            'enterprise_id' => $enterpriseId
        ]));

        return redirect()->back();
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return redirect()->back();
    }
}
