<?php

namespace App\Http\Controllers;

use App\Models\ManualStockEntry;
use App\Models\StockExitType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StockExitTypeController extends Controller
{
    // Same convention as ProductController's categories: structural configuration a
    // farm_manager/super_admin sets up, not something data_entry manages day to day.

    private function assertExitTypeInScope(Request $request, StockExitType $exitType): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $exitType->farm_id === $farmId, 403);
    }

    // Guarantees a stable, unique-per-farm key even if two types are given the same label,
    // or a label slugifies to nothing (emoji-only, etc).
    private function uniqueKeyFor(string $label, int $farmId): string
    {
        $base = Str::slug($label, '_') ?: 'type';
        $key = $base;
        $suffix = 2;

        while (StockExitType::where('farm_id', $farmId)->where('key', $key)->exists()) {
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
            'label' => ['required', 'string', 'max:255', Rule::unique('stock_exit_types', 'label')->where('farm_id', $farmId)],
            'requires_maintenance_log' => 'boolean',
        ]);

        StockExitType::create([
            'farm_id' => $farmId,
            'key' => $this->uniqueKeyFor($validated['label'], $farmId),
            'label' => $validated['label'],
            'requires_maintenance_log' => $validated['requires_maintenance_log'] ?? false,
        ]);

        return redirect()->back()->with('success', 'Type de sortie créé avec succès.');
    }

    public function update(Request $request, StockExitType $exitType)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }
        $this->assertExitTypeInScope($request, $exitType);

        $validated = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('stock_exit_types', 'label')->where('farm_id', $exitType->farm_id)->ignore($exitType->id)],
            'requires_maintenance_log' => 'boolean',
            'is_active' => 'boolean',
        ]);

        // The key is never re-derived from a renamed label — it's already stored on every
        // past entry for this type, so changing it would orphan that history.
        $exitType->update($validated);

        return redirect()->back()->with('success', 'Type de sortie mis à jour.');
    }

    public function destroy(Request $request, StockExitType $exitType)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }
        $this->assertExitTypeInScope($request, $exitType);

        if (ManualStockEntry::where('farm_id', $exitType->farm_id)->where('entry_type', $exitType->key)->exists()) {
            return redirect()->back()->withErrors([
                'exit_type' => 'Ce type est utilisé par au moins une sortie de stock et ne peut pas être supprimé. Désactivez-le plutôt.',
            ]);
        }

        $exitType->delete();

        return redirect()->back()->with('success', 'Type de sortie supprimé avec succès.');
    }
}
