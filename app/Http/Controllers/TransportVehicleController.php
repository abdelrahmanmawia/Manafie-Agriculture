<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\TransportCompany;
use App\Models\TransportVehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TransportVehicleController extends Controller
{
    private function assertInScope(Request $request, TransportVehicle $vehicle): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $vehicle->farm_id === $farmId, 403);
    }

    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $vehicles = TransportVehicle::with(['transportCompany', 'employees.residenceLocation'])
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->orderBy('code')
            ->get();

        return Inertia::render('Transport/Vehicles', [
            // net_per_day computed server-side (TransportVehicle::netPerDay()) so the page can
            // show each vehicle's current daily cost — auto or fixed — without recomputing the
            // rider-price sum in JS.
            'vehicles' => $vehicles->map(fn ($v) => array_merge($v->toArray(), ['net_per_day' => $v->netPerDay()])),
            'companies' => TransportCompany::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            // Riders are picked per-vehicle here (see syncEmployees()) rather than per-employee on
            // Admin/Employees.jsx — much faster when assigning a whole van's worth of people at
            // once. transport_vehicle_id is included so the page can show who's on THIS vehicle
            // vs already riding another one.
            'employees' => Employee::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->where('is_active', true)
                ->orderBy('full_name')
                ->get(['id', 'matricule', 'full_name', 'transport_vehicle_id', 'residence_location_id']),
            // For the checklist's inline "quick-set residence" picker (see
            // TransportLocationController::assignEmployeeResidence()) — an employee with no
            // residence can't be priced in TransportService, so the page flags and fixes it
            // right there instead of sending the manager to Admin/Employees.jsx.
            'locations' => \App\Models\TransportLocation::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'price_per_person']),
        ]);
    }

    /**
     * Bulk-sets this vehicle's riders to exactly the given employee list — checked in the UI's
     * multi-select, not accumulated one at a time. An employee already riding a different
     * vehicle is silently moved to this one (an employee can only ride one vehicle), same as
     * re-picking them individually on the employee form would have done.
     */
    public function syncEmployees(Request $request, TransportVehicle $transportVehicle)
    {
        $this->assertInScope($request, $transportVehicle);

        $validated = $request->validate([
            'employee_ids' => 'array',
            'employee_ids.*' => [Rule::exists('employees', 'id')->where('farm_id', $transportVehicle->farm_id)],
        ]);
        $employeeIds = $validated['employee_ids'] ?? [];

        DB::transaction(function () use ($transportVehicle, $employeeIds) {
            Employee::where('transport_vehicle_id', $transportVehicle->id)
                ->whereNotIn('id', $employeeIds)
                ->update(['transport_vehicle_id' => null]);

            Employee::whereIn('id', $employeeIds)->update(['transport_vehicle_id' => $transportVehicle->id]);
        });

        return redirect()->back()->with('success', 'Employés du véhicule mis à jour.');
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canAccessPointage(), 403);

        $farmId = $this->resolveWriteFarmId($request);

        $validated = $request->validate([
            'transport_company_id' => ['required', Rule::exists('transport_companies', 'id')->where('farm_id', $farmId)],
            'code' => ['required', 'string', 'max:100', Rule::unique('transport_vehicles', 'code')->where('farm_id', $farmId)],
            'driver_name' => 'nullable|string|max:255',
            'driver_phone' => 'nullable|string|max:50',
            'capacity' => 'nullable|integer|min:0',
            'fixed_net_per_day' => 'nullable|numeric|min:0',
        ]);

        TransportVehicle::create($validated + ['farm_id' => $farmId]);

        return redirect()->back()->with('success', 'Véhicule de transport créé avec succès.');
    }

    public function update(Request $request, TransportVehicle $transportVehicle)
    {
        $this->assertInScope($request, $transportVehicle);

        $validated = $request->validate([
            'transport_company_id' => ['sometimes', 'required', Rule::exists('transport_companies', 'id')->where('farm_id', $transportVehicle->farm_id)],
            'code' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('transport_vehicles', 'code')->where('farm_id', $transportVehicle->farm_id)->ignore($transportVehicle->id)],
            'driver_name' => 'nullable|string|max:255',
            'driver_phone' => 'nullable|string|max:50',
            'capacity' => 'nullable|integer|min:0',
            'fixed_net_per_day' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $transportVehicle->update($validated);

        return redirect()->back()->with('success', 'Véhicule de transport mis à jour.');
    }

    public function destroy(Request $request, TransportVehicle $transportVehicle)
    {
        abort_unless($request->user()->canAccessPointage(), 403);
        $this->assertInScope($request, $transportVehicle);

        if ($transportVehicle->employees()->exists()) {
            return redirect()->back()->withErrors([
                'transport_vehicle' => 'Ce véhicule est assigné à au moins un employé et ne peut pas être supprimé. Désactivez-le plutôt.',
            ]);
        }

        $transportVehicle->delete();

        return redirect()->back()->with('success', 'Véhicule de transport supprimé avec succès.');
    }
}
