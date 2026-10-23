<?php

namespace App\Http\Controllers;

use App\Models\TransportCompany;
use App\Models\TransportVehicle;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TransportVehicleController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        return Inertia::render('Transport/Vehicles', [
            'vehicles' => TransportVehicle::with('transportCompany')
                ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->orderBy('code')
                ->get(),
            'companies' => TransportCompany::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            // Riders are now the members of the équipes riding each vehicle (managed on the
            // Équipes page); listed here read-only so the cost basis stays visible.
            'equipes' => \App\Models\Equipe::where('farm_id', $farmId)
                ->whereNotNull('transport_vehicle_id')
                ->withCount('employees')
                ->orderBy('name')
                ->get(['id', 'name', 'transport_vehicle_id', 'is_active']),
        ]);
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
            'price_per_person' => 'nullable|numeric|min:0',
            'fixed_net_per_day' => 'nullable|numeric|min:0',
        ]);

        TransportVehicle::create($validated + ['farm_id' => $farmId]);

        return redirect()->back()->with('success', 'Véhicule de transport créé avec succès.');
    }

    public function update(Request $request, TransportVehicle $transportVehicle)
    {
        $this->assertFarmScope($request, $transportVehicle->farm_id);

        $validated = $request->validate([
            'transport_company_id' => ['sometimes', 'required', Rule::exists('transport_companies', 'id')->where('farm_id', $transportVehicle->farm_id)],
            'code' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('transport_vehicles', 'code')->where('farm_id', $transportVehicle->farm_id)->ignore($transportVehicle->id)],
            'driver_name' => 'nullable|string|max:255',
            'driver_phone' => 'nullable|string|max:50',
            'capacity' => 'nullable|integer|min:0',
            'price_per_person' => 'nullable|numeric|min:0',
            'fixed_net_per_day' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $transportVehicle->update($validated);

        return redirect()->back()->with('success', 'Véhicule de transport mis à jour.');
    }

    public function destroy(Request $request, TransportVehicle $transportVehicle)
    {
        abort_unless($request->user()->canAccessPointage(), 403);
        $this->assertFarmScope($request, $transportVehicle->farm_id);

        if ($transportVehicle->equipes()->exists()) {
            return redirect()->back()->withErrors([
                'transport_vehicle' => 'Au moins une équipe utilise ce véhicule : il ne peut pas être supprimé. Désactivez-le plutôt.',
            ]);
        }

        $transportVehicle->delete();

        return redirect()->back()->with('success', 'Véhicule de transport supprimé avec succès.');
    }
}
