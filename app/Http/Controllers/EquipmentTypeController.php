<?php

namespace App\Http\Controllers;

use App\Models\EquipmentType;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EquipmentTypeController extends Controller
{
    // Same convention as VehicleTypeController/StockExitTypeController: structural
    // configuration a farm_manager/super_admin sets up, not something data_entry manages.

    private function assertEquipmentTypeInScope(Request $request, EquipmentType $equipmentType): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $equipmentType->farm_id === $farmId, 403);
    }

    private function uniqueKeyFor(string $label, int $farmId): string
    {
        $base = Str::slug($label, '_') ?: 'type';
        $key = $base;
        $suffix = 2;

        while (EquipmentType::where('farm_id', $farmId)->where('key', $key)->exists()) {
            $key = "{$base}_{$suffix}";
            $suffix++;
        }

        return $key;
    }

    public function store(Request $request)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }

        $farmId = $this->resolveWriteFarmId($request);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255', Rule::unique('equipment_types', 'label')->where('farm_id', $farmId)],
        ]);

        EquipmentType::create([
            'farm_id' => $farmId,
            'key' => $this->uniqueKeyFor($validated['label'], $farmId),
            'label' => $validated['label'],
        ]);

        return redirect()->back()->with('success', "Type d'équipement créé avec succès.");
    }

    public function update(Request $request, EquipmentType $equipmentType)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }
        $this->assertEquipmentTypeInScope($request, $equipmentType);

        $validated = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('equipment_types', 'label')->where('farm_id', $equipmentType->farm_id)->ignore($equipmentType->id)],
            'is_active' => 'boolean',
        ]);

        // The key is never re-derived from a renamed label — it's already stored on every
        // piece of equipment of this type, so changing it would orphan that history.
        $equipmentType->update($validated);

        return redirect()->back()->with('success', "Type d'équipement mis à jour.");
    }

    public function destroy(Request $request, EquipmentType $equipmentType)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }
        $this->assertEquipmentTypeInScope($request, $equipmentType);

        if (Vehicle::where('farm_id', $equipmentType->farm_id)->where('asset_type', 'equipment')->where('type', $equipmentType->key)->exists()) {
            return redirect()->back()->withErrors([
                'equipment_type' => "Ce type est utilisé par au moins un équipement et ne peut pas être supprimé. Désactivez-le plutôt.",
            ]);
        }

        $equipmentType->delete();

        return redirect()->back()->with('success', "Type d'équipement supprimé avec succès.");
    }
}
