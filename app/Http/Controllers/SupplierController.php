<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SupplierController extends Controller
{
    // Same convention as ProductCategory/EquipmentType/StockExitType: structural configuration
    // a farm_manager/super_admin sets up, not something data_entry manages.

    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        return Inertia::render('Stock/Suppliers/Index', [
            'suppliers' => Supplier::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
        ]);
    }

    public function show(Request $request, Supplier $supplier)
    {
        $this->assertFarmScope($request, $supplier->farm_id);

        $supplier->load(['stockMovements' => fn ($q) => $q->orderByDesc('date')->with('product')]);
        // A cancelled réception stays listed (marked) but delivered nothing.
        $delivered = $supplier->stockMovements->whereNull('annulation_id');

        return Inertia::render('Stock/Suppliers/Show', [
            'supplier' => $supplier,
            'stats' => [
                'total_deliveries' => $delivered->count(),
                'total_value' => (float) $delivered->sum('total_cost'),
                'distinct_products' => $delivered->pluck('product_id')->unique()->count(),
                'last_delivery_date' => $delivered->max('date'),
            ],
        ]);
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
        $this->assertFarmScope($request, $supplier->farm_id);

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
        $this->assertFarmScope($request, $supplier->farm_id);

        if (StockMovement::where('supplier_id', $supplier->id)->exists()) {
            return redirect()->back()->withErrors([
                'supplier' => 'Ce fournisseur est utilisé par au moins un mouvement de stock et ne peut pas être supprimé. Désactivez-le plutôt.',
            ]);
        }

        $supplier->delete();

        return redirect()->back()->with('success', 'Fournisseur supprimé avec succès.');
    }
}
