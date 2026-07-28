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
use App\Services\StockAlertService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        $products = Product::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name', 'category', 'unit_type', 'unit_cost']);
        $employees = Employee::where('is_active', true)
            ->when($farmId, fn ($q) => $q->whereHas('enterprise', fn ($eq) => $eq->where('farm_id', $farmId)))
            ->get(['id', 'full_name']);
        $vehicles = Vehicle::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name', 'plate_number', 'type']);
        $blocs = Bloc::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name']);
        $sectors = Sector::when($farmId, fn ($q) => $q->whereHas('bloc', fn ($bq) => $bq->where('farm_id', $farmId)))->get(['id', 'name', 'bloc_id']);
        $parcelles = Parcelle::when($farmId, fn ($q) => $q->whereHas('bloc', fn ($bq) => $bq->where('farm_id', $farmId)))->get(['id', 'name', 'bloc_id', 'sector_id']);
        $operations = Operation::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name']);


        return Inertia::render('Stock/ManualStockEntries/Index', [
            'manualStockEntries' => $manualStockEntries,
            'products' => $products,
            'employees' => $employees,
            'vehicles' => $vehicles,
            'blocs' => $blocs,
            'sectors' => $sectors,
            'parcelles' => $parcelles,
            'operations' => $operations,        ]);
    }

    public function store(Request $request)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }

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
            'odometer_km' => 'nullable|numeric|min:0',
        ]);

        $this->validateLocationHierarchy($validated['bloc_id'] ?? null, $validated['sector_id'] ?? null, $validated['parcelle_id'] ?? null);

        $farmId = $this->resolveWriteFarmId($request);

        return DB::transaction(function () use ($validated, $request, $farmId) {
            $product = Product::findOrFail($validated['product_id']);

            // Cost basis is the CUMP (weighted-average cost built up from every réception),
            // not a price re-entered here — a sortie shouldn't ask what was already paid in.
            $inventory = StockInventory::firstOrCreate(
                ['product_id' => $product->id],
                [
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                ]
            );
            $unitCost = $inventory->average_cost ?? $product->unit_cost ?? 0;

            // A sortie can never take out more than the magasin physically holds.
            if ($validated['quantity'] > $inventory->quantity_on_hand) {
                $available = max(0, $inventory->quantity_on_hand);
                throw ValidationException::withMessages([
                    'quantity' => "Quantité insuffisante en stock. Disponible : {$available} {$product->unit_type}.",
                ]);
            }

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
                'odometer_km' => $validated['odometer_km'] ?? null,
            ]);

            // Create corresponding stock movement
            StockMovement::create([
                'product_id' => $product->id,
                'movement_type' => 'out', // Manual entries are typically 'out' movements
                'quantity' => $validated['quantity'],
                'unit_cost' => $unitCost,
                'total_cost' => $unitCost * $validated['quantity'],
                'reference_type' => 'manual_entry',
                'reference_id' => $entry->id,
                'performed_by' => $request->user()->id,
                'date' => $validated['date'],
                'notes' => "Manual entry: {$validated['entry_type']}",
            ]);

            // Update inventory
            $inventory->quantity_on_hand -= $validated['quantity'];
            $inventory->save();

            StockAlertService::syncLowStock($product);

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
        $products = Product::where('farm_id', $entry->farm_id)->get(['id', 'name', 'category', 'unit_type', 'unit_cost']);
        // Keep the entry's currently assigned employee selectable even if they've since gone
        // inactive, so editing the entry doesn't silently drop that field.
        $employees = Employee::whereHas('enterprise', fn ($q) => $q->where('farm_id', $entry->farm_id))
            ->where(function ($q) use ($entry) {
                $q->where('is_active', true);
                if ($entry->employee_id) {
                    $q->orWhere('id', $entry->employee_id);
                }
            })
            ->get(['id', 'full_name']);
        $vehicles = Vehicle::where('farm_id', $entry->farm_id)->get(['id', 'name', 'plate_number', 'type']);
        $blocs = Bloc::where('farm_id', $entry->farm_id)->get(['id', 'name']);
        $sectors = Sector::whereHas('bloc', fn ($bq) => $bq->where('farm_id', $entry->farm_id))->get(['id', 'name', 'bloc_id']);
        $parcelles = Parcelle::whereHas('bloc', fn ($bq) => $bq->where('farm_id', $entry->farm_id))->get(['id', 'name', 'bloc_id', 'sector_id']);
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
            'odometer_km' => 'nullable|numeric|min:0',
        ]);

        $this->validateLocationHierarchy(
            $validated['bloc_id'] ?? $entry->bloc_id,
            $validated['sector_id'] ?? $entry->sector_id,
            $validated['parcelle_id'] ?? $entry->parcelle_id
        );

        return DB::transaction(function () use ($validated, $entry) {
            $oldProductId = $entry->product_id;
            $oldQuantity = $entry->quantity;
            $newProductId = $validated['product_id'] ?? $oldProductId;
            $newQuantity = $validated['quantity'] ?? $oldQuantity;
            $productChanged = $newProductId != $oldProductId;

            $oldInventory = StockInventory::where('product_id', $oldProductId)->first();
            $newInventory = $productChanged
                ? StockInventory::firstOrCreate(['product_id' => $newProductId], ['quantity_on_hand' => 0, 'quantity_reserved' => 0])
                : $oldInventory;

            // A sortie can never take out more than the magasin physically holds. When the
            // product didn't change, the old quantity is being given back before the new one
            // is taken out, so it counts toward what's available.
            $available = ($newInventory->quantity_on_hand ?? 0) + ($productChanged ? 0 : $oldQuantity);

            if ($newQuantity > $available) {
                $product = Product::findOrFail($newProductId);
                $displayAvailable = max(0, $available);
                throw ValidationException::withMessages([
                    'quantity' => "Quantité insuffisante en stock. Disponible : {$displayAvailable} {$product->unit_type}.",
                ]);
            }

            $entry->update($validated);

            $product = Product::findOrFail($entry->product_id);
            $unitCost = $newInventory?->average_cost ?? $product->unit_cost ?? 0;

            // Update corresponding stock movement
            $movement = StockMovement::where('reference_type', 'manual_entry')
                                    ->where('reference_id', $entry->id)
                                    ->first();
            if ($movement) {
                $movement->update([
                    'product_id' => $entry->product_id,
                    'movement_type' => 'out',
                    'quantity' => $entry->quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $unitCost * $entry->quantity,
                    'date' => $entry->date,
                    'notes' => "Manual entry: {$entry->entry_type}",
                ]);
            }

            // Update inventory
            if ($productChanged) {
                if ($oldInventory) {
                    $oldInventory->quantity_on_hand += $oldQuantity; // give the old product its stock back
                    $oldInventory->save();
                }
                $newInventory->quantity_on_hand -= $newQuantity;
                $newInventory->save();
            } elseif ($oldInventory) {
                $oldInventory->quantity_on_hand += ($oldQuantity - $newQuantity); // adjust by the net difference
                $oldInventory->save();
            }

            StockAlertService::syncLowStock($product);
            if ($productChanged) {
                StockAlertService::syncLowStock(Product::findOrFail($oldProductId));
            }

            return redirect()->route('stock.manual-entries.show', $entry);
        });
    }

    public function destroy(Request $request, ManualStockEntry $entry)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }

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

            StockAlertService::syncLowStock(Product::findOrFail($entry->product_id));

            return redirect()->route('stock.manual-entries.index');
        });
    }

    public function verify(Request $request, ManualStockEntry $entry)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }

        $entry->update([
            'is_verified' => true,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        return redirect()->back();
    }

    // A secteur/parcelle picked independently of its bloc (e.g. a direct API call bypassing
    // the cascading Bloc→Secteur→Parcelle selects) would silently misattribute this entry's
    // cost to the wrong bloc in reports like Coût par Hectare.
    private function validateLocationHierarchy(?int $blocId, ?int $sectorId, ?int $parcelleId): void
    {
        if ($sectorId) {
            $sector = Sector::find($sectorId);
            if (! $sector || $sector->bloc_id !== $blocId) {
                throw ValidationException::withMessages([
                    'sector_id' => 'Le secteur sélectionné n\'appartient pas au bloc choisi.',
                ]);
            }
        }

        if ($parcelleId) {
            $parcelle = Parcelle::find($parcelleId);
            if (! $parcelle || $parcelle->sector_id !== $sectorId) {
                throw ValidationException::withMessages([
                    'parcelle_id' => 'La parcelle sélectionnée n\'appartient pas au secteur choisi.',
                ]);
            }
        }
    }
}
