<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\StockInventory;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia; // Import Inertia

class StockInventoryController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $query = StockInventory::with('product')
            ->when($farmId, function ($query) use ($farmId) {
                $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId));
            });

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('low_stock')) {
            $query->whereHas('product', function ($q) {
                $q->whereColumn('quantity_on_hand', '<=', 'min_stock_level');
            });
        }

        $inventory = $query->get();

        return Inertia::render('Stock/Inventory/Index', [
            'stockInventory' => $inventory,
            'farms' => $request->user()->role === 'super_admin' ? Farm::all(['id', 'name']) : [],
            'selectedFarmId' => $farmId,
        ]);
    }

    public function show(StockInventory $inventory)
    {
        $inventory->load('product', 'product.stockMovements.performedBy', 'product.stockMovements.bloc', 'product.stockMovements.sector', 'product.stockMovements.parcelle', 'product.stockMovements.vehicle');

        return Inertia::render('Stock/Inventory/Show', [
            'stockInventory' => $inventory,
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric',
            'reason' => 'required|string|max:255',
        ]);

        $product = Product::findOrFail($validated['product_id']);

        $inventory = StockInventory::firstOrCreate(
            ['product_id' => $product->id],
            [
                'quantity_on_hand' => 0,
                'quantity_reserved' => 0,
            ]
        );

        $oldQuantity = $inventory->quantity_on_hand;
        $inventory->quantity_on_hand = $validated['quantity'];
        $inventory->last_count_date = now();
        $inventory->save();

        // Create stock movement for adjustment
        $movement = $inventory->product->stockMovements()->create([
            'movement_type' => 'adjustment',
            'quantity' => $validated['quantity'] - $oldQuantity,
            'unit_cost' => $product->unit_cost,
            'total_cost' => $product->unit_cost * ($validated['quantity'] - $oldQuantity),
            'performed_by' => $request->user()->id,
            'date' => now(),
            'notes' => "Manual adjustment: {$validated['reason']}",
        ]);

        return response()->json($inventory);
    }

    public function count(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'counted_quantity' => 'required|numeric',
            'batch_number' => 'nullable|string',
        ]);

        $product = Product::findOrFail($validated['product_id']);

        $inventory = StockInventory::firstOrCreate(
            [
                'product_id' => $product->id,
                'batch_number' => $validated['batch_number'] ?? null,
            ],
            [
                'quantity_on_hand' => 0,
                'quantity_reserved' => 0,
            ]
        );

        $oldQuantity = $inventory->quantity_on_hand;
        $inventory->quantity_on_hand = $validated['counted_quantity'];
        $inventory->last_count_date = now();
        $inventory->save();

        // Create stock movement for count adjustment
        $difference = $validated['counted_quantity'] - $oldQuantity;
        $inventory->product->stockMovements()->create([
            'movement_type' => 'adjustment',
            'quantity' => $difference,
            'unit_cost' => $product->unit_cost,
            'total_cost' => $product->unit_cost * $difference,
            'performed_by' => $request->user()->id,
            'date' => now(),
            'notes' => "Stock count adjustment. Previous: {$oldQuantity}, Counted: {$validated['counted_quantity']}",
        ]);

        return response()->json($inventory);
    }

    public function movements(Product $product): JsonResponse
    {
        $movements = $product->stockMovements()
            ->with('performedBy', 'bloc', 'sector', 'parcelle', 'vehicle')
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($movements);
    }
}
