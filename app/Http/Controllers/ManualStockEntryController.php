<?php

namespace App\Http\Controllers;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\Operation;
use App\Models\Parcelle;
use App\Models\Product;
use App\Models\Sector;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceLog;
use App\Services\StockAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ManualStockEntryController extends Controller
{
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
        $filters = $request->validate([
            'entry_type' => 'nullable|string|max:50',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'search' => 'nullable|string|max:100',
        ]);

        $query = ManualStockEntry::query()
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->when($filters['entry_type'] ?? null, fn ($q, $type) => $q->where('entry_type', $type))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"))
                ->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$term}%"))
                ->orWhereHas('vehicle', fn ($v) => $v->where('name', 'like', "%{$term}%"))
                ->orWhere('employee_name', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%")));

        $manualStockEntries = $query->with('product', 'employee', 'vehicle', 'maintenanceLog', 'operation', 'bloc', 'sector', 'parcelle', 'enteredBy')
            ->orderBy('date', 'desc')->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();

        // With what the magasin holds, so the form shows it beside each product line.
        $products = Product::with('category')->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('is_active', true)
            ->orderBy('name')
            ->select('id', 'name', 'category_id', 'unit_type', 'unit_cost')
            ->addSelect(['quantity_on_hand' => StockInventory::select('quantity_on_hand')->whereColumn('product_id', 'products.id')->limit(1)])
            ->get();
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
            'filters' => (object) array_filter($filters, fn ($v) => $v !== null && $v !== ''),
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

    /**
     * A sortie: one product, or several taken at once (`lignes`: product + quantity each) sharing
     * the same date, person, type, destination and vehicle — one entry and one movement per
     * product, all saved or none. A fuel consumption line is a fill-up (ravitaillement()).
     */
    public function store(Request $request)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }

        $farmId = $this->resolveWriteFarmId($request);
        $exitTypeKeys = $this->exitTypesFor($farmId)->pluck('key');

        $validated = $request->validate([
            // One product (product_id + quantity) or several (lignes).
            'product_id' => 'required_without:lignes|exists:products,id',
            'quantity' => 'required_without:lignes|numeric|min:0.01',
            'lignes' => 'sometimes|array|min:1|max:50',
            'lignes.*.product_id' => 'required|distinct|exists:products,id',
            'lignes.*.quantity' => 'required|numeric|min:0.01',
            // 'transfer' removed: bloc-to-bloc transfers don't happen in this business — see the
            // deleted StockMovementController::transfer() and CLAUDE.md's Stock domain notes. A
            // sortie has no receiving side, so labeling one "Transfert" implied stock went
            // somewhere trackable when it just left the magasin like any other consumption.
            'entry_type' => ['required', Rule::in($exitTypeKeys)],
            'employee_id' => 'nullable|exists:employees,id',
            // Free-text fallback for someone who took stock but isn't in the Employee list — the
            // form only ever sends one of the two (a toggle switches which field is active), but
            // nothing here forces that; either can be null on its own.
            'employee_name' => 'nullable|string|max:255',
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
        ], [
            'lignes.*.product_id.distinct' => 'Ce produit est déjà dans la liste.',
            'lignes.*.quantity.required' => 'Quantité ?',
        ]);

        $this->validateLocationHierarchy($validated['bloc_id'] ?? null, $validated['sector_id'] ?? null, $validated['parcelle_id'] ?? null);

        $this->assertForeignKeysInScope($validated, $farmId);

        // The intervention picked must actually belong to the vehicle this sortie is for —
        // otherwise a part could get attributed to the wrong repair's cost.
        if (! empty($validated['maintenance_log_id'])) {
            $log = VehicleMaintenanceLog::findOrFail($validated['maintenance_log_id']);
            abort_unless($log->farm_id === $farmId && $log->vehicle_id == ($validated['vehicle_id'] ?? null), 403);
        }

        $plusieurs = isset($validated['lignes']);
        $lignes = collect($validated['lignes'] ?? [['product_id' => $validated['product_id'], 'quantity' => $validated['quantity']]]);
        $products = Product::with('category')->whereIn('id', $lignes->pluck('product_id'))->get()->keyBy('id');
        abort_unless($products->every(fn ($p) => $p->farm_id === $farmId), 403);

        // Fuel consumed is a vehicle's fill-up: it goes to the Carburant page and the fuel cost per
        // vehicle, not the sorties (a loss or theft of fuel stays a plain sortie).
        $isPlein = fn (Product $product) => $product->category?->is_carburant && $validated['entry_type'] === 'consumption';
        if (empty($validated['vehicle_id']) && $products->contains($isPlein)) {
            throw ValidationException::withMessages(['vehicle_id' => 'Choisissez le véhicule ou matériel ravitaillé.']);
        }

        $pleins = DB::transaction(function () use ($lignes, $products, $isPlein, $plusieurs, $validated, $request, $farmId) {
            $pleins = 0;
            // Locked in product order, so two sorties sharing products can't deadlock.
            foreach ($lignes->sortBy('product_id', SORT_NUMERIC, false) as $index => $ligne) {
                $product = $products[$ligne['product_id']];
                $quantity = (float) $ligne['quantity'];
                $errorKey = $plusieurs ? "lignes.{$index}.quantity" : 'quantity';
                $inventory = $this->lockedInventoryFor($product->id);

                // A sortie can never take out more than the magasin physically holds.
                if ($quantity > $inventory->quantity_on_hand) {
                    $available = max(0, (float) $inventory->quantity_on_hand);
                    throw ValidationException::withMessages([
                        $errorKey => "Quantité insuffisante en stock. Disponible : {$available} {$product->unit_type}.",
                    ]);
                }

                if ($isPlein($product)) {
                    $this->ravitaillement($request, $validated, $product, $farmId, $inventory, $quantity);
                    $pleins++;
                } else {
                    $this->sortie($request, $validated, $product, $farmId, $inventory, $quantity);
                }
                StockAlertService::syncLowStock($product);
            }

            return $pleins;
        });

        if (! $plusieurs) {
            return redirect()->back()->with('success', $pleins ? 'Ravitaillement enregistré — il apparaît dans Carburant.' : 'Sortie de stock enregistrée avec succès.');
        }

        return redirect()->back()->with('success', $lignes->count().' produit(s) sorti(s)'
            .($pleins ? ", dont {$pleins} ravitaillement(s) (dans Carburant)" : '').'.');
    }

    /** One product's sortie: the entry, its "out" movement at the CUMP, the stock taken out. */
    private function sortie(Request $request, array $validated, Product $product, int $farmId, StockInventory $inventory, float $quantity): void
    {
        // Cost basis is the CUMP (weighted-average cost built up from every réception),
        // not a price re-entered here — a sortie shouldn't ask what was already paid in.
        $unitCost = (float) ($inventory->average_cost ?? $product->unit_cost ?? 0);

        $entry = ManualStockEntry::create([
            'farm_id' => $farmId,
            'product_id' => $product->id,
            'entry_type' => $validated['entry_type'],
            'quantity' => $quantity,
            'employee_id' => $validated['employee_id'] ?? null,
            'employee_name' => $validated['employee_name'] ?? null,
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

        StockMovement::create([
            'product_id' => $product->id,
            'movement_type' => 'out',
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost * $quantity,
            'reference_type' => 'manual_entry',
            'reference_id' => $entry->id,
            'performed_by' => $request->user()->id,
            'date' => $validated['date'],
            // The sortie's own note, shown wherever the movement is (Inventory/Show.jsx,
            // Movements/Index.jsx, the Stock exports) — mirrors stockIn()'s notes handling.
            'notes' => $validated['notes'] ?? null,
        ]);

        $inventory->quantity_on_hand -= $quantity;
        $inventory->save();
    }

    /**
     * A fuel sortie: a FuelTransaction ("Ravitaillement") with its "out" movement, at the fuel's
     * CUMP — the same rules as any sortie.
     */
    private function ravitaillement(Request $request, array $validated, Product $product, int $farmId, StockInventory $inventory, float $quantity): void
    {
        $price = (float) ($inventory->average_cost ?? $product->unit_cost ?? 0);

        $transaction = FuelTransaction::create([
            'farm_id' => $farmId,
            'vehicle_id' => $validated['vehicle_id'],
            'product_id' => $product->id,
            'transaction_type' => 'fueling',
            'quantity_liters' => $quantity,
            'unit_price_per_liter' => $price,
            'total_cost' => round($price * $quantity, 2),
            'driver_id' => $validated['employee_id'] ?? null,
            'performed_by' => $request->user()->id,
            'date' => $validated['date'],
            'odometer_km' => $validated['odometer_km'] ?? null,
            'notes' => trim(($validated['employee_name'] ?? '') ? 'Chauffeur : '.$validated['employee_name'].'. '.($validated['notes'] ?? '') : ($validated['notes'] ?? '')) ?: null,
            'operation_id' => $validated['operation_id'] ?? null,
            'bloc_id' => $validated['bloc_id'] ?? null,
            'sector_id' => $validated['sector_id'] ?? null,
            'parcelle_id' => $validated['parcelle_id'] ?? null,
        ]);
        StockMovement::create([
            'product_id' => $product->id,
            'movement_type' => 'out',
            'quantity' => $quantity,
            'unit_cost' => $price,
            'total_cost' => round($price * $quantity, 2),
            'reference_type' => 'fuel_transaction',
            'reference_id' => $transaction->id,
            'performed_by' => $request->user()->id,
            'date' => $validated['date'],
            'notes' => $validated['notes'] ?? null,
        ]);
        $inventory->quantity_on_hand -= $quantity;
        $inventory->save();
    }

    public function show(Request $request, ManualStockEntry $manualStockEntry)
    {
        $this->assertFarmScope($request, $manualStockEntry->farm_id);

        $manualStockEntry->load('product', 'employee', 'vehicle', 'maintenanceLog', 'operation', 'bloc', 'sector', 'parcelle', 'enteredBy');

        return Inertia::render('Stock/ManualStockEntries/Show', [
            'manualStockEntry' => $manualStockEntry,
        ]);
    }

    public function edit(Request $request, ManualStockEntry $manualStockEntry)
    {
        $this->assertFarmScope($request, $manualStockEntry->farm_id);

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
        $this->assertFarmScope($request, $manualStockEntry->farm_id);

        $exitTypeKeys = $this->exitTypesFor($manualStockEntry->farm_id, $manualStockEntry->entry_type)->pluck('key');

        $validated = $request->validate([
            'product_id' => 'sometimes|required|exists:products,id',
            'entry_type' => ['sometimes', 'required', Rule::in($exitTypeKeys)],
            'quantity' => 'sometimes|required|numeric|min:0.01',
            'employee_id' => 'nullable|exists:employees,id',
            'employee_name' => 'nullable|string|max:255',
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

        // A sortie can't be turned into a fill-up: that is a FuelTransaction, recorded as such
        // (one recorded before the rule stays editable).
        $isFuel = fn ($productId, $type) => $type === 'consumption' && Product::with('category')->find($productId)?->category?->is_carburant;
        if ($isFuel($validated['product_id'] ?? $manualStockEntry->product_id, $validated['entry_type'] ?? $manualStockEntry->entry_type)
            && ! $isFuel($manualStockEntry->product_id, $manualStockEntry->entry_type)) {
            throw ValidationException::withMessages(['product_id' => 'Un plein de carburant ne se modifie pas depuis une sortie : supprimez-la et enregistrez le ravitaillement.']);
        }

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
            // Re-read the entry under lock: its old quantity/product is what gets given back to
            // stock below, so two overlapping saves of the same entry must not both start from
            // the same pre-edit values.
            $manualStockEntry = ManualStockEntry::lockForUpdate()->findOrFail($manualStockEntry->id);

            $oldProductId = $manualStockEntry->product_id;
            $oldQuantity = $manualStockEntry->quantity;
            $newProductId = $validated['product_id'] ?? $oldProductId;
            $newQuantity = $validated['quantity'] ?? $oldQuantity;
            $productChanged = $newProductId != $oldProductId;

            if ($productChanged) {
                abort_unless(Product::findOrFail($newProductId)->farm_id === $manualStockEntry->farm_id, 403);
            }

            // Lock both inventory rows in product_id order so two edits swapping products in
            // opposite directions can't deadlock each other.
            $productIds = array_unique([(int) $oldProductId, (int) $newProductId]);
            sort($productIds);
            $inventories = [];
            foreach ($productIds as $productId) {
                $inventories[$productId] = $this->lockedInventoryFor($productId);
            }
            $oldInventory = $inventories[(int) $oldProductId];
            $newInventory = $inventories[(int) $newProductId];

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
                    // Same fix as store() — reflect the entry's own (just-updated) note instead
                    // of the auto "Manual entry: {type}" placeholder.
                    'notes' => $manualStockEntry->notes,
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
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }

        $entry = ManualStockEntry::find($manualStockEntry);
        if (! $entry) {
            abort(404);
        }

        $this->assertFarmScope($request, $entry->farm_id);

        return DB::transaction(function () use ($entry) {
            // Re-read under lock: a double-clicked delete would otherwise have both requests
            // pass the find() above and each give the quantity back to stock.
            $entry = ManualStockEntry::lockForUpdate()->find($entry->id);
            if (! $entry) {
                return back();
            }

            // Revert stock movement
            $movement = StockMovement::where('reference_type', 'manual_entry')
                ->where('reference_id', $entry->id)
                ->first();
            if ($movement) {
                $inventory = $this->lockedInventoryFor($entry->product_id);
                $inventory->quantity_on_hand += $entry->quantity; // Add back to stock
                $inventory->save();
                $movement->delete();
            }

            $entry->delete();

            StockAlertService::syncLowStock(Product::findOrFail($entry->product_id));

            return back()->with('success', 'Entrée supprimée avec succès.');
        });
    }

    // Every optional foreign key below was previously validated only with exists:TABLE,id —
    // proving the ID exists SOMEWHERE, not that it belongs to this farm. A cross-farm ID here
    // would silently misattribute stock cost/consumption to another farm's employee, vehicle,
    // operation, bloc, sector, or parcelle, and leak that farm's name into this farm's own
    // reports (Coût par Hectare, Coût par Véhicule, etc.) — same class of gap already fixed
    // for product_id/maintenance_log_id in store()/update().
    private function assertForeignKeysInScope(array $validated, int $farmId): void
    {
        if (! empty($validated['employee_id'])) {
            abort_unless(Employee::whereHas('enterprise', fn ($q) => $q->where('farm_id', $farmId))->where('id', $validated['employee_id'])->exists(), 403);
        }
        if (! empty($validated['vehicle_id'])) {
            abort_unless(Vehicle::where('id', $validated['vehicle_id'])->where('farm_id', $farmId)->exists(), 403);
        }
        if (! empty($validated['operation_id'])) {
            abort_unless(Operation::where('id', $validated['operation_id'])->where('farm_id', $farmId)->exists(), 403);
        }
        if (! empty($validated['bloc_id'])) {
            abort_unless(Bloc::where('id', $validated['bloc_id'])->where('farm_id', $farmId)->exists(), 403);
        }
        if (! empty($validated['sector_id'])) {
            abort_unless(Sector::whereHas('bloc', fn ($q) => $q->where('farm_id', $farmId))->where('id', $validated['sector_id'])->exists(), 403);
        }
        if (! empty($validated['parcelle_id'])) {
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
