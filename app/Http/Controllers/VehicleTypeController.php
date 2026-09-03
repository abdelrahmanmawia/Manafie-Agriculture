<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VehicleTypeController extends Controller
{
    // Same convention as StockExitTypeController/ProductController's categories: structural
    // configuration a farm_manager/super_admin sets up, not something data_entry manages.

    private function assertVehicleTypeInScope(Request $request, VehicleType $vehicleType): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $vehicleType->farm_id === $farmId, 403);
    }

    private function uniqueKeyFor(string $label, int $farmId): string
    {
        $base = Str::slug($label, '_') ?: 'type';
        $key = $base;
        $suffix = 2;

        while (VehicleType::where('farm_id', $farmId)->where('key', $key)->exists()) {
            $key = "{$base}_{$suffix}";
            $suffix++;
        }

        return $key;
    }

    public function store(Request $request)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }

        $farmId = $this->resolveWriteFarmId($request);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255', Rule::unique('vehicle_types', 'label')->where('farm_id', $farmId)],
        ]);

        VehicleType::create([
            'farm_id' => $farmId,
            'key' => $this->uniqueKeyFor($validated['label'], $farmId),
            'label' => $validated['label'],
        ]);

        return redirect()->back()->with('success', 'Type de véhicule créé avec succès.');
    }

    public function update(Request $request, VehicleType $vehicleType)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }
        $this->assertVehicleTypeInScope($request, $vehicleType);

        $validated = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('vehicle_types', 'label')->where('farm_id', $vehicleType->farm_id)->ignore($vehicleType->id)],
            'is_active' => 'boolean',
        ]);

        // The key is never re-derived from a renamed label — it's already stored on every
        // vehicle of this type, so changing it would orphan that history.
        $vehicleType->update($validated);

        return redirect()->back()->with('success', 'Type de véhicule mis à jour.');
    }

    public function destroy(Request $request, VehicleType $vehicleType)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }
        $this->assertVehicleTypeInScope($request, $vehicleType);

        if (Vehicle::where('farm_id', $vehicleType->farm_id)->where('asset_type', 'vehicle')->where('type', $vehicleType->key)->exists()) {
            return redirect()->back()->withErrors([
                'vehicle_type' => 'Ce type est utilisé par au moins un véhicule et ne peut pas être supprimé. Désactivez-le plutôt.',
            ]);
        }

        $vehicleType->delete();

        return redirect()->back()->with('success', 'Type de véhicule supprimé avec succès.');
    }
}
