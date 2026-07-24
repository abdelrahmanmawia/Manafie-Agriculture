<?php

namespace App\Http\Controllers;

use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia; // Import Inertia

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $query = StockMovement::with([
                'product',
                'performedBy',
                'reference' => function ($morphTo) {
                    $morphTo->morphWith([
                        ManualStockEntry::class => ['bloc', 'sector', 'parcelle', 'vehicle'],
                        FuelTransaction::class => ['vehicle'],
                    ]);
                },
            ])
            ->when($farmId, function ($query) use ($farmId) {
                $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId));
            });

        if ($request->has('movement_type')) {
            $query->where('movement_type', $request->movement_type);
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('from_date')) {
            $query->where('date', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('date', '<=', $request->to_date);
        }

        $movements = $query->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $products = Product::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit_type', 'unit_cost']);

        return Inertia::render('Stock/Movements/Index', [
            'stockMovements' => $movements,
            'products' => $products,
        ]);
    }

    public function stockIn(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0.01',
            'unit_cost' => 'nullable|numeric|min:0',
            'batch_number' => 'nullable|string|max:255',
            'date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($validated, $request) {
            $product = Product::findOrFail($validated['product_id']);
            $unitCost = $validated['unit_cost'] ?? $product->unit_cost ?? 0;

            StockMovement::create([
                'product_id' => $product->id,
                'movement_type' => 'in',
                'quantity' => $validated['quantity'],
                'unit_cost' => $unitCost,
                'total_cost' => $unitCost * $validated['quantity'],
                // No reference_type: a réception has no source document to link back to —
                // see StockMovement::reference() and AppServiceProvider's morph map.
                'performed_by' => $request->user()->id,
                'date' => $validated['date'],
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update inventory: quantity + weighted-average cost + latest batch info
            $inventory = StockInventory::firstOrCreate(
                ['product_id' => $product->id],
                [
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                ]
            );

            $previousQuantity = (float) $inventory->quantity_on_hand;
            $newQuantity = $previousQuantity + $validated['quantity'];

            $inventory->average_cost = $newQuantity > 0
                ? ((((float) ($inventory->average_cost ?? 0)) * $previousQuantity) + ($unitCost * $validated['quantity'])) / $newQuantity
                : $unitCost;
            $inventory->quantity_on_hand = $newQuantity;
            $inventory->last_restock_date = $validated['date'];

            if (! empty($validated['batch_number'])) {
                $inventory->batch_number = $validated['batch_number'];
            }

            $inventory->save();
        });

        return redirect()->back();
    }
}
