<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\TransportLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TransportLocationController extends Controller
{
    private function assertInScope(Request $request, TransportLocation $location): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $location->farm_id === $farmId, 403);
    }

    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        return Inertia::render('Transport/Locations', [
            'locations' => TransportLocation::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canAccessPointage(), 403);

        $farmId = $this->resolveWriteFarmId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('transport_locations', 'name')->where('farm_id', $farmId)],
            'price_per_person' => 'required|numeric|min:0',
        ]);

        TransportLocation::create($validated + ['farm_id' => $farmId]);

        return redirect()->back()->with('success', 'Emplacement créé avec succès.');
    }

    public function update(Request $request, TransportLocation $transportLocation)
    {
        $this->assertInScope($request, $transportLocation);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('transport_locations', 'name')->where('farm_id', $transportLocation->farm_id)->ignore($transportLocation->id)],
            'price_per_person' => 'sometimes|required|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $transportLocation->update($validated);

        return redirect()->back()->with('success', 'Emplacement mis à jour.');
    }

    public function destroy(Request $request, TransportLocation $transportLocation)
    {
        abort_unless($request->user()->canAccessPointage(), 403);
        $this->assertInScope($request, $transportLocation);

        if ($transportLocation->employees()->exists()) {
            return redirect()->back()->withErrors([
                'transport_location' => 'Cet emplacement est utilisé par au moins un employé et ne peut pas être supprimé. Désactivez-le plutôt.',
            ]);
        }

        $transportLocation->delete();

        return redirect()->back()->with('success', 'Emplacement supprimé avec succès.');
    }

    /**
     * Quick-set one employee's residence — used from the vehicle rider checklist
     * (Transport/Vehicles.jsx) so a manager can fix a missing residence right there while
     * assigning riders, instead of leaving to Admin/Employees.jsx for a single field.
     */
    public function assignEmployeeResidence(Request $request, Employee $employee)
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $employee->farm_id === $farmId, 403);

        $validated = $request->validate([
            'residence_location_id' => ['nullable', Rule::exists('transport_locations', 'id')->where('farm_id', $farmId)],
        ]);

        $employee->update($validated);

        return redirect()->back()->with('success', 'Résidence mise à jour.');
    }
}
