<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockInventory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia; // Import Inertia

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $query = StockMovement::with('product', 'performedBy', 'bloc', 'sector', 'parcelle', 'vehicle')
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

        return Inertia::render('Stock/Movements/Index', [
            'stockMovements' => $movements,        ]);
    }

    public function stockIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $product = Product::findOrFail($validated['product_id']);

            $movement = StockMovement::create([
                'product_id' => $product->id,
                'movement_type' => 'in',
                'quantity' => $validated['quantity'],
                'unit_cost' => $validated['unit_cost'] ?? $product->unit_cost,
                'total_cost' => ($validated['unit_cost'] ?? $product->unit_cost) * $validated['quantity'],
                'reference_type' => $validated['reference_type'] ?? null,
                'reference_id' => $validated['reference_id'] ?? null,
                'performed_by' => $request->user()->id,
                'date' => $validated['date'],
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update inventory
            $inventory = StockInventory::firstOrCreate(
                ['product_id' => $product->id],
                [
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                ]
            );

            $inventory->quantity_on_hand += $validated['quantity'];
            $inventory->last_restock_date = $validated['date'];
            $inventory->save();

            return response()->json($movement, 201);
        });
    }

    public function stockOut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'date' => 'required|date',
            'notes' => 'nullable|string',
            'bloc_id' => 'nullable|exists:blocs,id',
            'sector_id' => 'nullable|exists:sectors,id',
            'parcelle_id' => 'nullable|exists:parcelles,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $product = Product::findOrFail($validated['product_id']);

            $movement = StockMovement::create([
                'product_id' => $product->id,
                'movement_type' => 'out',
                'quantity' => $validated['quantity'],
                'unit_cost' => $validated['unit_cost'] ?? $product->unit_cost,
                'total_cost' => ($validated['unit_cost'] ?? $product->unit_cost) * $validated['quantity'],
                'reference_type' => $validated['reference_type'] ?? null,
                'reference_id' => $validated['reference_id'] ?? null,
                'performed_by' => $request->user()->id,
                'date' => $validated['date'],
                'notes' => $validated['notes'] ?? null,
                'bloc_id' => $validated['bloc_id'] ?? null,
                'sector_id' => $validated['sector_id'] ?? null,
                'parcelle_id' => $validated['parcelle_id'] ?? null,
                'vehicle_id' => $validated['vehicle_id'] ?? null,
            ]);

            // Update inventory
            $inventory = StockInventory::where('product_id', $product->id)->first();

            if ($inventory) {
                $inventory->quantity_on_hand -= $validated['quantity'];
                $inventory->save();
            }

            return response()->json($movement, 201);
        });
    }

    public function adjustment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric',
            'unit_cost' => 'nullable|numeric|min:0',
            'date' => 'required|date',
            'notes' => 'required|string',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $product = Product::findOrFail($validated['product_id']);

            $movement = StockMovement::create([
                'product_id' => $product->id,
                'movement_type' => 'adjustment',
                'quantity' => $validated['quantity'],
                'unit_cost' => $validated['unit_cost'] ?? $product->unit_cost,
                'total_cost' => ($validated['unit_cost'] ?? $product->unit_cost) * $validated['quantity'],
                'performed_by' => $request->user()->id,
                'date' => $validated['date'],
                'notes' => $validated['notes'],
            ]);

            // Update inventory
            $inventory = StockInventory::where('product_id', $product->id)->first();

            if ($inventory) {
                $inventory->quantity_on_hand += $validated['quantity'];
                $inventory->save();
            }

            return response()->json($movement, 201);
        });
    }
}
