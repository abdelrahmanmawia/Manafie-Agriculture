<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\ManualStockEntry;
use App\Models\Product;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\Bloc;
use App\Models\Sector;
use App\Models\Parcelle;
use App\Models\Operation; // Assuming an Operation model exists
use App\Models\StockMovement;
use App\Models\StockInventory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class ManualStockEntryController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $query = ManualStockEntry::with('product', 'employee', 'vehicle', 'operation', 'bloc', 'sector', 'parcelle', 'enteredBy', 'verifiedBy')
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId));

        if ($request->has('entry_type')) {
            $query->where('entry_type', $request->entry_type);
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->has('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }

        if ($request->has('from_date')) {
            $query->where('date', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('date', '<=', $request->to_date);
        }

        $manualStockEntries = $query->orderBy('date', 'desc')->get();

        $products = Product::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name', 'unit_type', 'unit_cost']);
        $employees = Employee::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'full_name']);
        $vehicles = Vehicle::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name', 'plate_number']);
        $blocs = Bloc::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name']);
        $sectors = Sector::when($farmId, fn ($q) => $q->whereHas('bloc', fn ($bq) => $bq->where('farm_id', $farmId)))->get(['id', 'name']);
        $parcelles = Parcelle::when($farmId, fn ($q) => $q->whereHas('bloc', fn ($bq) => $bq->where('farm_id', $farmId)))->get(['id', 'name']);
        $operations = Operation::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name']);


        return Inertia::render('Stock/ManualStockEntries/Index', [
            'manualStockEntries' => $manualStockEntries,
            'products' => $products,
            'employees' => $employees,
            'vehicles' => $vehicles,
            'blocs' => $blocs,
            'sectors' => $sectors,
            'parcelles' => $parcelles,
            'operations' => $operations,
            'farms' => $request->user()->role === 'super_admin' ? Farm::all(['id', 'name']) : [],
            'selectedFarmId' => $farmId,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'entry_type' => 'required|in:consumption,transfer,loss,theft,damage',
            'quantity' => 'required|numeric|min:0',
            'employee_id' => 'nullable|exists:employees,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'pointage_record_id' => 'nullable|exists:pointage_records,id',
            'operation_id' => 'nullable|exists:operations,id',
            'bloc_id' => 'nullable|exists:blocs,id',
            'sector_id' => 'nullable|exists:sectors,id',
            'parcelle_id' => 'nullable|exists:parcelles,id',
            'date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $farmId = $this->resolveWriteFarmId($request);

        return DB::transaction(function () use ($validated, $request, $farmId) {
            $product = Product::findOrFail($validated['product_id']);

            $entry = ManualStockEntry::create([
                'farm_id' => $farmId,
                'product_id' => $validated['product_id'],
                'entry_type' => $validated['entry_type'],
                'quantity' => $validated['quantity'],
                'employee_id' => $validated['employee_id'] ?? null,
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'pointage_record_id' => $validated['pointage_record_id'] ?? null,
                'operation_id' => $validated['operation_id'] ?? null,
                'bloc_id' => $validated['bloc_id'] ?? null,
                'sector_id' => $validated['sector_id'] ?? null,
                'parcelle_id' => $validated['parcelle_id'] ?? null,
                'date' => $validated['date'],
                'entered_by' => $request->user()->id,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Create corresponding stock movement
            StockMovement::create([
                'product_id' => $product->id,
                'movement_type' => 'out', // Manual entries are typically 'out' movements
                'quantity' => $validated['quantity'],
                'unit_cost' => $product->unit_cost,
                'total_cost' => $product->unit_cost * $validated['quantity'],
                'reference_type' => 'manual_entry',
                'reference_id' => $entry->id,
                'performed_by' => $request->user()->id,
                'date' => $validated['date'],
                'notes' => "Manual entry: {$validated['entry_type']}",
                'bloc_id' => $validated['bloc_id'] ?? null,
                'sector_id' => $validated['sector_id'] ?? null,
                'parcelle_id' => $validated['parcelle_id'] ?? null,
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
            $inventory->quantity_on_hand -= $validated['quantity'];
            $inventory->save();

            return redirect()->back();
        });
    }

    public function show(ManualStockEntry $entry)
    {
        $entry->load('product', 'employee', 'vehicle', 'operation', 'bloc', 'sector', 'parcelle', 'enteredBy', 'verifiedBy');

        return Inertia::render('Stock/ManualStockEntries/Show', [
            'manualStockEntry' => $entry,
        ]);
    }

    public function edit(ManualStockEntry $entry)
    {
        $products = Product::where('farm_id', $entry->farm_id)->get(['id', 'name', 'unit_type', 'unit_cost']);
        $employees = Employee::where('farm_id', $entry->farm_id)->get(['id', 'full_name']);
        $vehicles = Vehicle::where('farm_id', $entry->farm_id)->get(['id', 'name', 'plate_number']);
        $blocs = Bloc::where('farm_id', $entry->farm_id)->get(['id', 'name']);
        $sectors = Sector::whereHas('bloc', fn ($bq) => $bq->where('farm_id', $entry->farm_id))->get(['id', 'name']);
        $parcelles = Parcelle::whereHas('bloc', fn ($bq) => $bq->where('farm_id', $entry->farm_id))->get(['id', 'name']);
        $operations = Operation::where('farm_id', $entry->farm_id)->get(['id', 'name']);

        return Inertia::render('Stock/ManualStockEntries/Edit', [
            'manualStockEntry' => $entry,
            'products' => $products,
            'employees' => $employees,
            'vehicles' => $vehicles,
            'blocs' => $blocs,
            'sectors' => $sectors,
            'parcelles' => $parcelles,
            'operations' => $operations,
        ]);
    }

    public function update(Request $request, ManualStockEntry $entry)
    {
        $validated = $request->validate([
            'product_id' => 'sometimes|required|exists:products,id',
            'entry_type' => 'sometimes|required|in:consumption,transfer,loss,theft,damage',
            'quantity' => 'sometimes|required|numeric|min:0',
            'employee_id' => 'nullable|exists:employees,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'pointage_record_id' => 'nullable|exists:pointage_records,id',
            'operation_id' => 'nullable|exists:operations,id',
            'bloc_id' => 'nullable|exists:blocs,id',
            'sector_id' => 'nullable|exists:sectors,id',
            'parcelle_id' => 'nullable|exists:parcelles,id',
            'date' => 'sometimes|required|date',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $entry) {
            $oldQuantity = $entry->quantity;
            $entry->update($validated);

            $product = Product::findOrFail($entry->product_id);

            // Update corresponding stock movement
            $movement = StockMovement::where('reference_type', 'manual_entry')
                                    ->where('reference_id', $entry->id)
                                    ->first();
            if ($movement) {
                $movement->update([
                    'product_id' => $entry->product_id,
                    'movement_type' => 'out',
                    'quantity' => $entry->quantity,
                    'unit_cost' => $product->unit_cost,
                    'total_cost' => $product->unit_cost * $entry->quantity,
                    'date' => $entry->date,
                    'notes' => "Manual entry: {$entry->entry_type}",
                    'bloc_id' => $entry->bloc_id,
                    'sector_id' => $entry->sector_id,
                    'parcelle_id' => $entry->parcelle_id,
                    'vehicle_id' => $entry->vehicle_id,
                ]);
            }

            // Update inventory
            $inventory = StockInventory::where('product_id', $entry->product_id)->first();
            if ($inventory) {
                $inventory->quantity_on_hand += ($oldQuantity - $entry->quantity); // Adjust difference
                $inventory->save();
            }

            return redirect()->route('stock.manual-entries.show', $entry);
        });
    }

    public function destroy(ManualStockEntry $entry)
    {
        return DB::transaction(function () use ($entry) {
            // Revert stock movement
            $movement = StockMovement::where('reference_type', 'manual_entry')
                                    ->where('reference_id', $entry->id)
                                    ->first();
            if ($movement) {
                $inventory = StockInventory::where('product_id', $entry->product_id)->first();
                if ($inventory) {
                    $inventory->quantity_on_hand += $entry->quantity; // Add back to stock
                    $inventory->save();
                }
                $movement->delete();
            }

            $entry->delete();
            return redirect()->route('stock.manual-entries.index');
        });
    }

    public function verify(Request $request, ManualStockEntry $entry)
    {
        $entry->update([
            'is_verified' => true,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        return redirect()->back();
    }

    // Quick entry for common scenarios
    public function fuelForVehicle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'driver_id' => 'required|exists:employees,id',
            'quantity_liters' => 'required|numeric|min:0',
            'date' => 'required|date',
            'odometer_km' => 'nullable|numeric',
            'hours_worked' => 'nullable|numeric',
            'pointage_record_id' => 'nullable|exists:pointage_records,id',
            'bloc_id' => 'nullable|exists:blocs,id',
            'sector_id' => 'nullable|exists:sectors,id',
            'parcelle_id' => 'nullable|exists:parcelles,id',
            'notes' => 'nullable|string'
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $vehicle = Vehicle::find($validated['vehicle_id']);
            $fuelProduct = Product::where('category', 'fuel')
                ->where('farm_id', $vehicle->farm_id)
                ->firstOrFail();

            // Create FuelTransaction record
            $fuelTransaction = FuelTransaction::create([
                'farm_id' => $vehicle->farm_id,
                'vehicle_id' => $validated['vehicle_id'],
                'product_id' => $fuelProduct->id,
                'transaction_type' => 'fueling',
                'quantity_liters' => $validated['quantity_liters'],
                'unit_price_per_liter' => $fuelProduct->unit_cost,
                'total_cost' => $fuelProduct->unit_cost * $validated['quantity_liters'],
                'driver_id' => $validated['driver_id'],
                'performed_by' => $request->user()->id,
                'date' => $validated['date'],
                'odometer_km' => $validated['odometer_km'] ?? null,
                'hours_worked' => $validated['hours_worked'] ?? null,
                'notes' => $validated['notes'] ?? null
            ]);

            // Create the corresponding stock movement, same as FuelTransactionController::store()
            StockMovement::create([
                'product_id' => $fuelProduct->id,
                'movement_type' => 'out',
                'quantity' => $validated['quantity_liters'],
                'unit_cost' => $fuelProduct->unit_cost,
                'total_cost' => $fuelProduct->unit_cost * $validated['quantity_liters'],
                'reference_type' => 'fuel_transaction',
                'reference_id' => $fuelTransaction->id,
                'performed_by' => $request->user()->id,
                'date' => $validated['date'],
                'notes' => "Fuel transaction for vehicle {$vehicle->name}",
                'vehicle_id' => $validated['vehicle_id'],
            ]);

            // Deduct the fuel from inventory
            $inventory = StockInventory::firstOrCreate(
                ['product_id' => $fuelProduct->id],
                ['quantity_on_hand' => 0, 'quantity_reserved' => 0]
            );
            $inventory->quantity_on_hand -= $validated['quantity_liters'];
            $inventory->save();

            // Create ManualStockEntry for consumption reporting/context (bloc, pointage record, etc.);
            // the stock movement above already adjusted inventory, this does not duplicate it.
            $manualEntry = ManualStockEntry::create([
                'farm_id' => $vehicle->farm_id,
                'product_id' => $fuelProduct->id,
                'entry_type' => 'consumption',
                'quantity' => $validated['quantity_liters'],
                'employee_id' => $validated['driver_id'],
                'vehicle_id' => $validated['vehicle_id'],
                'pointage_record_id' => $validated['pointage_record_id'] ?? null,
                'bloc_id' => $validated['bloc_id'] ?? null,
                'sector_id' => $validated['sector_id'] ?? null,
                'parcelle_id' => $validated['parcelle_id'] ?? null,
                'date' => $validated['date'],
                'entered_by' => $request->user()->id,
                'notes' => "Fuel for vehicle via quick entry: {$vehicle->name}",
            ]);

            return response()->json(['fuelTransaction' => $fuelTransaction, 'manualEntry' => $manualEntry], 201);
        });
    }

    public function materialsForOperation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0',
            'operation_id' => 'required|exists:operations,id',
            'date' => 'required|date',
            'bloc_id' => 'nullable|exists:blocs,id',
            'sector_id' => 'nullable|exists:sectors,id',
            'parcelle_id' => 'nullable|exists:parcelles,id',
            'employee_id' => 'nullable|exists:employees,id',
            'notes' => 'nullable|string',
        ]);

        $farmId = $this->resolveWriteFarmId($request);

        return DB::transaction(function () use ($validated, $request, $farmId) {
            $product = Product::findOrFail($validated['product_id']);

            $manualEntry = ManualStockEntry::create([
                'farm_id' => $farmId,
                'product_id' => $validated['product_id'],
                'entry_type' => 'consumption',
                'quantity' => $validated['quantity'],
                'employee_id' => $validated['employee_id'] ?? null,
                'operation_id' => $validated['operation_id'],
                'bloc_id' => $validated['bloc_id'] ?? null,
                'sector_id' => $validated['sector_id'] ?? null,
                'parcelle_id' => $validated['parcelle_id'] ?? null,
                'date' => $validated['date'],
                'entered_by' => $request->user()->id,
                'notes' => "Materials for operation via quick entry: {$product->name}",
            ]);

            // Create corresponding stock movement
            StockMovement::create([
                'product_id' => $product->id,
                'movement_type' => 'out',
                'quantity' => $validated['quantity'],
                'unit_cost' => $product->unit_cost,
                'total_cost' => $product->unit_cost * $validated['quantity'],
                'reference_type' => 'manual_entry',
                'reference_id' => $manualEntry->id,
                'performed_by' => $request->user()->id,
                'date' => $validated['date'],
                'notes' => "Materials for operation: {$product->name}",
                'bloc_id' => $validated['bloc_id'] ?? null,
                'sector_id' => $validated['sector_id'] ?? null,
                'parcelle_id' => $validated['parcelle_id'] ?? null,
            ]);

            // Update inventory
            $inventory = StockInventory::firstOrCreate(
                ['product_id' => $product->id],
                [
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                ]
            );
            $inventory->quantity_on_hand -= $validated['quantity'];
            $inventory->save();

            return response()->json($manualEntry, 201);
        });
    }
}
