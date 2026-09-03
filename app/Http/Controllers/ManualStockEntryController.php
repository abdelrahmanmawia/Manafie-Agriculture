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
use App\Models\VehicleMaintenanceLog;
use App\Services\StockAlertService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManualStockEntryController extends Controller
{
    /**
     * show/edit/update/destroy/verify all trusted the route-bound $entry with no
     * ownership check — any authenticated user could view or mutate another farm's
     * manual stock entry by walking IDs.
     */
    private function assertEntryInScope(Request $request, ManualStockEntry $entry): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $entry->farm_id === $farmId, 403);
    }

    // Lets the "Intervention liée" select on the sortie form filter, client-side, to the
    // logs belonging to whichever vehicle is picked — one query, no per-vehicle round trip.
    private function maintenanceLogsFor(?int $farmId)
    {
        return VehicleMaintenanceLog::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->orderByDesc('performed_at')
            ->get(['id', 'vehicle_id', 'description', 'performed_at']);
    }

    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $query = ManualStockEntry::with('product', 'employee', 'vehicle', 'maintenanceLog', 'operation', 'bloc', 'sector', 'parcelle', 'enteredBy', 'verifiedBy')
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

        $products = Product::with('category')->when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name', 'category_id', 'unit_type', 'unit_cost']);
        $employees = Employee::where('is_active', true)
            ->when($farmId, fn ($q) => $q->whereHas('enterprise', fn ($eq) => $eq->where('farm_id', $farmId)))
            ->get(['id', 'full_name']);
        $vehicles = Vehicle::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name', 'plate_number', 'serial_number', 'type', 'asset_type']);
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
            'operations' => $operations,
            'vehicleMaintenanceLogs' => $this->maintenanceLogsFor($farmId),
            'exitTypes' => $this->exitTypesFor($farmId),
        ]);
    }

    public function store(Request $request)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }

        $farmId = $this->resolveWriteFarmId($request);
        $exitTypeKeys = $this->exitTypesFor($farmId)->pluck('key');

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            // 'transfer' removed: bloc-to-bloc transfers don't happen in this business — see the
            // deleted StockMovementController::transfer() and CLAUDE.md's Stock domain notes. A
            // sortie has no receiving side, so labeling one "Transfert" implied stock went
            // somewhere trackable when it just left the magasin like any other consumption.
            'entry_type' => ['required', Rule::in($exitTypeKeys)],
            'quantity' => 'required|numeric|min:0.01',
            'employee_id' => 'nullable|exists:employees,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'maintenance_log_id' => 'nullable|exists:vehicle_maintenance_logs,id',
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

        $this->assertForeignKeysInScope($validated, $farmId);

        // The intervention picked must actually belong to the vehicle this sortie is for —
        // otherwise a part could get attributed to the wrong repair's cost.
        if (! empty($validated['maintenance_log_id'])) {
            $log = VehicleMaintenanceLog::findOrFail($validated['maintenance_log_id']);
            abort_unless($log->farm_id === $farmId && $log->vehicle_id == ($validated['vehicle_id'] ?? null), 403);
        }

        return DB::transaction(function () use ($validated, $request, $farmId) {
            $product = Product::findOrFail($validated['product_id']);
            abort_unless($product->farm_id === $farmId, 403);

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
                'maintenance_log_id' => $validated['maintenance_log_id'] ?? null,
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

            return redirect()->back()->with('success', 'Sortie de stock enregistrée avec succès.');
        });
    }

    public function show(Request $request, ManualStockEntry $manualStockEntry)
    {
        $this->assertEntryInScope($request, $manualStockEntry);

        $manualStockEntry->load('product', 'employee', 'vehicle', 'maintenanceLog', 'operation', 'bloc', 'sector', 'parcelle', 'enteredBy', 'verifiedBy');

        return Inertia::render('Stock/ManualStockEntries/Show', [
            'manualStockEntry' => $manualStockEntry,
        ]);
    }

    public function edit(Request $request, ManualStockEntry $manualStockEntry)
    {
        $this->assertEntryInScope($request, $manualStockEntry);

        $products = Product::with('category')->where('farm_id', $manualStockEntry->farm_id)->get(['id', 'name', 'category_id', 'unit_type', 'unit_cost']);
        // Keep the entry's currently assigned employee selectable even if they've since gone
        // inactive, so editing the entry doesn't silently drop that field.
        $employees = Employee::whereHas('enterprise', fn ($q) => $q->where('farm_id', $manualStockEntry->farm_id))
            ->where(function ($q) use ($manualStockEntry) {
                $q->where('is_active', true);
                if ($manualStockEntry->employee_id) {
                    $q->orWhere('id', $manualStockEntry->employee_id);
                }
            })
            ->get(['id', 'full_name']);
        $vehicles = Vehicle::where('farm_id', $manualStockEntry->farm_id)->get(['id', 'name', 'plate_number', 'serial_number', 'type', 'asset_type']);
        $blocs = Bloc::where('farm_id', $manualStockEntry->farm_id)->get(['id', 'name']);
        $sectors = Sector::whereHas('bloc', fn ($bq) => $bq->where('farm_id', $manualStockEntry->farm_id))->get(['id', 'name', 'bloc_id']);
        $parcelles = Parcelle::whereHas('bloc', fn ($bq) => $bq->where('farm_id', $manualStockEntry->farm_id))->get(['id', 'name', 'bloc_id', 'sector_id']);
        $operations = Operation::where('farm_id', $manualStockEntry->farm_id)->get(['id', 'name']);

        return Inertia::render('Stock/ManualStockEntries/Edit', [
            'manualStockEntry' => $manualStockEntry,
            'products' => $products,
            'employees' => $employees,
            'vehicles' => $vehicles,
            'blocs' => $blocs,
            'sectors' => $sectors,
            'parcelles' => $parcelles,
            'operations' => $operations,
            'vehicleMaintenanceLogs' => $this->maintenanceLogsFor($manualStockEntry->farm_id),
            'exitTypes' => $this->exitTypesFor($manualStockEntry->farm_id, $manualStockEntry->entry_type),
        ]);
    }

    public function update(Request $request, ManualStockEntry $manualStockEntry)
    {
        // data_entry is intentionally allowed to update (see test_data_entry_can_update_manual_stock_entry).
        $this->assertEntryInScope($request, $manualStockEntry);

        $exitTypeKeys = $this->exitTypesFor($manualStockEntry->farm_id, $manualStockEntry->entry_type)->pluck('key');

        $validated = $request->validate([
            'product_id' => 'sometimes|required|exists:products,id',
            'entry_type' => ['sometimes', 'required', Rule::in($exitTypeKeys)],
            'quantity' => 'sometimes|required|numeric|min:0.01',
            'employee_id' => 'nullable|exists:employees,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'maintenance_log_id' => 'nullable|exists:vehicle_maintenance_logs,id',
            'pointage_record_id' => 'nullable|exists:pointage_records,id',
            'operation_id' => 'nullable|exists:operations,id',
            'bloc_id' => 'nullable|exists:blocs,id',
            'sector_id' => 'nullable|exists:sectors,id',
            'parcelle_id' => 'nullable|exists:parcelles,id',
            'date' => 'sometimes|required|date',
            'notes' => 'nullable|string',
            'odometer_km' => 'nullable|numeric|min:0',
        ]);

        $this->assertForeignKeysInScope($validated, $manualStockEntry->farm_id);

        // Same cross-check as store(): the intervention must belong to the vehicle this
        // sortie ends up attached to (whichever value — new or existing — wins).
        if (! empty($validated['maintenance_log_id'])) {
            $log = VehicleMaintenanceLog::findOrFail($validated['maintenance_log_id']);
            abort_unless($log->farm_id === $manualStockEntry->farm_id && $log->vehicle_id == ($validated['vehicle_id'] ?? $manualStockEntry->vehicle_id), 403);
        }

        $this->validateLocationHierarchy(
            $validated['bloc_id'] ?? $manualStockEntry->bloc_id,
            $validated['sector_id'] ?? $manualStockEntry->sector_id,
            $validated['parcelle_id'] ?? $manualStockEntry->parcelle_id
        );

        return DB::transaction(function () use ($validated, $manualStockEntry) {
            $oldProductId = $manualStockEntry->product_id;
            $oldQuantity = $manualStockEntry->quantity;
            $newProductId = $validated['product_id'] ?? $oldProductId;
            $newQuantity = $validated['quantity'] ?? $oldQuantity;
            $productChanged = $newProductId != $oldProductId;

            if ($productChanged) {
                abort_unless(Product::findOrFail($newProductId)->farm_id === $manualStockEntry->farm_id, 403);
            }

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

            $manualStockEntry->update($validated);

            $product = Product::findOrFail($manualStockEntry->product_id);
            $unitCost = $newInventory?->average_cost ?? $product->unit_cost ?? 0;

            // Update corresponding stock movement
            $movement = StockMovement::where('reference_type', 'manual_entry')
                                    ->where('reference_id', $manualStockEntry->id)
                                    ->first();
            if ($movement) {
                $movement->update([
                    'product_id' => $manualStockEntry->product_id,
                    'movement_type' => 'out',
                    'quantity' => $manualStockEntry->quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $unitCost * $manualStockEntry->quantity,
                    'date' => $manualStockEntry->date,
                    'notes' => "Manual entry: {$manualStockEntry->entry_type}",
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

            return redirect()->route('stock.manual-entries.show', $manualStockEntry)->with('success', 'Entrée mise à jour avec succès.');
        });
    }

    public function destroy(Request $request, $manualStockEntry)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }

        $entry = ManualStockEntry::find($manualStockEntry);
        if (!$entry) {
            abort(404);
        }

        $farmId = $this->scopedFarmId($request);
        if ($entry->farm_id !== $farmId) {
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

            return back()->with('success', 'Entrée supprimée avec succès.');
        });
    }

    public function verify(Request $request, ManualStockEntry $manualStockEntry)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }
        $this->assertEntryInScope($request, $manualStockEntry);

        $manualStockEntry->update([
            'is_verified' => true,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        return back()->with('success', 'Entrée vérifiée avec succès.');
    }

    // Every optional foreign key below was previously validated only with exists:TABLE,id —
    // proving the ID exists SOMEWHERE, not that it belongs to this farm. A cross-farm ID here
    // would silently misattribute stock cost/consumption to another farm's employee, vehicle,
    // operation, bloc, sector, or parcelle, and leak that farm's name into this farm's own
    // reports (Coût par Hectare, Coût par Véhicule, etc.) — same class of gap already fixed
    // for product_id/maintenance_log_id in store()/update().
    private function assertForeignKeysInScope(array $validated, int $farmId): void
    {
        if (!empty($validated['employee_id'])) {
            abort_unless(Employee::whereHas('enterprise', fn ($q) => $q->where('farm_id', $farmId))->where('id', $validated['employee_id'])->exists(), 403);
        }
        if (!empty($validated['vehicle_id'])) {
            abort_unless(Vehicle::where('id', $validated['vehicle_id'])->where('farm_id', $farmId)->exists(), 403);
        }
        if (!empty($validated['operation_id'])) {
            abort_unless(Operation::where('id', $validated['operation_id'])->where('farm_id', $farmId)->exists(), 403);
        }
        if (!empty($validated['bloc_id'])) {
            abort_unless(Bloc::where('id', $validated['bloc_id'])->where('farm_id', $farmId)->exists(), 403);
        }
        if (!empty($validated['sector_id'])) {
            abort_unless(Sector::whereHas('bloc', fn ($q) => $q->where('farm_id', $farmId))->where('id', $validated['sector_id'])->exists(), 403);
        }
        if (!empty($validated['parcelle_id'])) {
            abort_unless(Parcelle::whereHas('bloc', fn ($q) => $q->where('farm_id', $farmId))->where('id', $validated['parcelle_id'])->exists(), 403);
        }
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
