<?php

namespace App\Http\Controllers;

use App\Models\FuelTransaction;
use App\Models\Vehicle;
use App\Models\Product;
use App\Models\Employee;
use App\Models\StockMovement;
use App\Models\StockInventory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class FuelTransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = FuelTransaction::with('vehicle', 'product', 'driver', 'performedBy')
            ->where('farm_id', $request->user()->farm_id);

        if ($request->has('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }

        if ($request->has('driver_id')) {
            $query->where('driver_id', $request->driver_id);
        }

        if ($request->has('from_date')) {
            $query->where('date', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('date', '<=', $request->to_date);
        }

        $fuelTransactions = $query->orderBy('date', 'desc')->get();

        $vehicles = Vehicle::where('farm_id', $request->user()->farm_id)->get(['id', 'name', 'plate_number']);
        $products = Product::where('farm_id', $request->user()->farm_id)->get(['id', 'name', 'category', 'unit_type', 'unit_cost']);
        $employees = Employee::where('farm_id', $request->user()->farm_id)->get(['id', 'full_name']);

        return Inertia::render('Stock/FuelTransactions/Index', [
            'fuelTransactions' => $fuelTransactions,
            'vehicles' => $vehicles,
            'products' => $products,
            'employees' => $employees,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'product_id' => 'required|exists:products,id',
            'transaction_type' => 'required|in:fueling,transfer,adjustment',
            'quantity_liters' => 'required|numeric|min:0',
            'unit_price_per_liter' => 'nullable|numeric|min:0',
            'driver_id' => 'nullable|exists:employees,id',
            'date' => 'required|date',
            'odometer_km' => 'nullable|numeric',
            'hours_worked' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $product = Product::findOrFail($validated['product_id']);
            $unitPrice = $validated['unit_price_per_liter'] ?? $product->unit_cost;
            $totalCost = $unitPrice * $validated['quantity_liters'];

            $fuelTransaction = FuelTransaction::create([
                'farm_id' => $request->user()->farm_id,
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'product_id' => $validated['product_id'],
                'transaction_type' => $validated['transaction_type'],
                'quantity_liters' => $validated['quantity_liters'],
                'unit_price_per_liter' => $unitPrice,
                'total_cost' => $totalCost,
                'driver_id' => $validated['driver_id'] ?? null,
                'performed_by' => $request->user()->id,
                'date' => $validated['date'],
                'odometer_km' => $validated['odometer_km'] ?? null,
                'hours_worked' => $validated['hours_worked'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Ensure vehicle relationship is loaded before accessing its properties
            $fuelTransaction->load('vehicle');

            // Create corresponding stock movement
            StockMovement::create([
                'product_id' => $product->id,
                'movement_type' => 'out', // Assuming fueling is an 'out' movement for the fuel product
                'quantity' => $validated['quantity_liters'],
                'unit_cost' => $unitPrice,
                'total_cost' => $totalCost,
                'reference_type' => 'fuel_transaction',
                'reference_id' => $fuelTransaction->id,
                'performed_by' => $request->user()->id,
                'date' => $validated['date'],
                'notes' => "Fuel transaction for vehicle " . (optional($fuelTransaction->vehicle)->name ?? 'N/A'),
                'vehicle_id' => $validated['vehicle_id'] ?? null,
            ]);

            // Update inventory
            $inventory = StockInventory::firstOrCreate(
                ['product_id' => $product->id],
                [
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                ]
            );
            $inventory->quantity_on_hand -= $validated['quantity_liters'];
            $inventory->save();

            return response()->json($fuelTransaction, 201);
        });
    }

    public function show(FuelTransaction $transaction)
    {
        $transaction->load('vehicle', 'product', 'driver', 'performedBy');

        return Inertia::render('Stock/FuelTransactions/Show', [
            'fuelTransaction' => $transaction,
        ]);
    }

    public function edit(FuelTransaction $transaction)
    {
        $vehicles = Vehicle::where('farm_id', $transaction->farm_id)->get(['id', 'name', 'plate_number']);
        $products = Product::where('farm_id', $transaction->farm_id)->get(['id', 'name', 'category', 'unit_type', 'unit_cost']);
        $employees = Employee::where('farm_id', $transaction->farm_id)->get(['id', 'full_name']);

        return Inertia::render('Stock/FuelTransactions/Edit', [
            'fuelTransaction' => $transaction,
            'vehicles' => $vehicles,
            'products' => $products,
            'employees' => $employees,
        ]);
    }

    public function update(Request $request, FuelTransaction $transaction): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'product_id' => 'sometimes|required|exists:products,id',
            'transaction_type' => 'sometimes|required|in:fueling,transfer,adjustment',
            'quantity_liters' => 'sometimes|required|numeric|min:0',
            'unit_price_per_liter' => 'nullable|numeric|min:0',
            'driver_id' => 'nullable|exists:employees,id',
            'date' => 'sometimes|required|date',
            'odometer_km' => 'nullable|numeric',
            'hours_worked' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $transaction) {
            $oldQuantity = $transaction->quantity_liters;
            $transaction->update($validated);

            // Ensure vehicle relationship is loaded after update
            $transaction->load('vehicle');

            $product = Product::findOrFail($transaction->product_id);
            $unitPrice = $transaction->unit_price_per_liter ?? $product->unit_cost;
            $totalCost = $unitPrice * $transaction->quantity_liters;
            $transaction->update(['unit_price_per_liter' => $unitPrice, 'total_cost' => $totalCost]);

            // Update corresponding stock movement
            $movement = StockMovement::where('reference_type', 'fuel_transaction')
                                    ->where('reference_id', $transaction->id)
                                    ->first();
            if ($movement) {
                $movement->update([
                    'product_id' => $transaction->product_id,
                    'quantity' => $transaction->quantity_liters,
                    'unit_cost' => $unitPrice,
                    'total_cost' => $totalCost,
                    'date' => $transaction->date,
                    'notes' => "Fuel transaction for vehicle " . (optional($transaction->vehicle)->name ?? 'N/A'),
                    'vehicle_id' => $transaction->vehicle_id,
                ]);
            }

            // Update inventory
            $inventory = StockInventory::where('product_id', $transaction->product_id)->first();
            if ($inventory) {
                $inventory->quantity_on_hand += ($oldQuantity - $transaction->quantity_liters); // Adjust difference
                $inventory->save();
            }

            return response()->json($transaction);
        });
    }

    public function destroy(FuelTransaction $transaction): JsonResponse
    {
        return DB::transaction(function () use ($transaction) {
            // Revert stock movement
            $movement = StockMovement::where('reference_type', 'fuel_transaction')
                                    ->where('reference_id', $transaction->id)
                                    ->first();
            if ($movement) {
                $inventory = StockInventory::where('product_id', $transaction->product_id)->first();
                if ($inventory) {
                    $inventory->quantity_on_hand += $transaction->quantity_liters; // Add back to stock
                    $inventory->save();
                }
                $movement->delete();
            }

            $transaction->delete();
            return response()->json(null, 204);
        });
    }
}
