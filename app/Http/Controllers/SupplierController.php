<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    // Same convention as ProductCategory/EquipmentType/StockExitType: structural configuration
    // a farm_manager/super_admin sets up, not something data_entry manages.

    private function assertSupplierInScope(Request $request, Supplier $supplier): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $supplier->farm_id === $farmId, 403);
    }

    public function store(Request $request)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }

        $farmId = $this->resolveWriteFarmId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')->where('farm_id', $farmId)],
        ]);

        Supplier::create([
            'farm_id' => $farmId,
            'name' => $validated['name'],
        ]);

        return redirect()->back()->with('success', 'Fournisseur créé avec succès.');
    }

    public function update(Request $request, Supplier $supplier)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }
        $this->assertSupplierInScope($request, $supplier);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('suppliers', 'name')->where('farm_id', $supplier->farm_id)->ignore($supplier->id)],
            'is_active' => 'boolean',
        ]);

        $supplier->update($validated);

        return redirect()->back()->with('success', 'Fournisseur mis à jour.');
    }

    public function destroy(Request $request, Supplier $supplier)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }
        $this->assertSupplierInScope($request, $supplier);

        if (StockMovement::where('supplier_id', $supplier->id)->exists()) {
            return redirect()->back()->withErrors([
                'supplier' => 'Ce fournisseur est utilisé par au moins un mouvement de stock et ne peut pas être supprimé. Désactivez-le plutôt.',
            ]);
        }

        $supplier->delete();

        return redirect()->back()->with('success', 'Fournisseur supprimé avec succès.');
    }
}
