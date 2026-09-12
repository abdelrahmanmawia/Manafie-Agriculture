<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Farm;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Vehicle;
use App\Models\StockInventory;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\StockAlert;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Employee;
use App\Models\Bloc;
use App\Models\Sector;
use App\Models\Parcelle;
use App\Models\Operation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class StockSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding Stock Management Data...');

        // Was hardcoded to the SQLite-only `PRAGMA foreign_keys` syntax, which throws a syntax
        // error on the app's real MySQL connection — Schema::disable/enableForeignKeyConstraints()
        // emit the right statement for whichever connection is actually active.
        Schema::disableForeignKeyConstraints();

        Product::truncate();
        ProductCategory::truncate();
        Vehicle::truncate();
        StockInventory::truncate();
        FuelTransaction::truncate();
        ManualStockEntry::truncate();
        StockAlert::truncate();
        StockMovement::truncate();
        Supplier::truncate();

        Schema::enableForeignKeyConstraints();

        $farms = Farm::all();
        $allEmployees = Employee::all(); // Get all employees once
        $allUsers = User::all(); // Get all users once
        $allBlocs = Bloc::all(); // Get all blocs once
        $allSectors = Sector::all(); // Get all sectors once
        $allParcelles = Parcelle::all(); // Get all parcelles once
        $allOperations = Operation::all(); // Get all operations once

        if ($farms->isEmpty()) {
            $this->command->warn('No farms found. Please run FarmSeeder first.');
            return;
        }

        foreach ($farms as $farm) {
            $this->command->info("Seeding stock data for Farm: {$farm->name}");

            // Filter related models by current farm
            $farmEmployees = $allEmployees->where('enterprise_id', $farm->enterprises->first()->id ?? null);
            $farmUsers = $allUsers; // Users are not directly tied to farm, can be any user
            $farmBlocs = $allBlocs->where('farm_id', $farm->id);
            $farmSectors = $allSectors->whereIn('bloc_id', $farmBlocs->pluck('id'));
            $farmParcelles = $allParcelles->whereIn('sector_id', $farmSectors->pluck('id'));
            $farmOperations = $allOperations->where('farm_id', $farm->id);

            // 1. Products — "Huile Moteur" is vehicle_needs (not "fuel" itself, but still tied to
            // a vehicle when it leaves the magasin) so it exercises the same UI path as Gasoil.
            // Note: a vehicle (e.g. a tractor) is never seeded here as a Product — it's already
            // tracked by the Vehicle model, and treating it as a consumable stock item that gets
            // "sortied" by the unit produces nonsensical costs (unit_cost × quantity at vehicle scale).
            $productsData = [
                ['name' => 'Semences de Tomate', 'category' => 'seeds', 'unit_type' => 'units', 'min_stock_level' => 500, 'unit_cost' => 0.15],
                ['name' => 'Engrais NPK 15-15-15', 'category' => 'fertilizers', 'unit_type' => 'kg', 'min_stock_level' => 1000, 'unit_cost' => 12.50],
                ['name' => 'Insecticide Bio', 'category' => 'pesticides', 'unit_type' => 'liters', 'min_stock_level' => 50, 'unit_cost' => 85.00],
                ['name' => 'Petite Pelle', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 10, 'unit_cost' => 45.00],
                ['name' => 'Sacs de 25kg', 'category' => 'packaging', 'unit_type' => 'units', 'min_stock_level' => 200, 'unit_cost' => 2.00],
                ['name' => 'Gasoil', 'category' => 'fuel', 'unit_type' => 'liters', 'min_stock_level' => 500, 'unit_cost' => 13.00],
                ['name' => 'Huile Moteur', 'category' => 'vehicle_needs', 'unit_type' => 'liters', 'min_stock_level' => 20, 'unit_cost' => 60.00],
            ];

            // Categories are a real per-farm table (ProductCategory), not a fixed enum — resolve
            // each demo product's category key to a row (creating it if this farm has none yet).
            $categoryIdsByKey = [];
            $vehicleRelatedKeys = ['fuel', 'vehicle_needs'];
            $categoryLabels = [
                'seeds' => 'Semences', 'fertilizers' => 'Engrais', 'pesticides' => 'Pesticides',
                'tools' => 'Outils', 'packaging' => 'Emballage', 'fuel' => 'Carburant',
                'vehicle_needs' => 'Besoins Véhicule',
            ];

            $products = collect();
            foreach ($productsData as $data) {
                $categoryKey = $data['category'];
                if (! isset($categoryIdsByKey[$categoryKey])) {
                    $categoryIdsByKey[$categoryKey] = ProductCategory::firstOrCreate(
                        ['farm_id' => $farm->id, 'name' => $categoryLabels[$categoryKey] ?? ucfirst($categoryKey)],
                        ['is_vehicle_related' => in_array($categoryKey, $vehicleRelatedKeys, true)]
                    )->id;
                }

                $productData = array_merge($data, [
                    'farm_id' => $farm->id,
                    'category_id' => $categoryIdsByKey[$categoryKey],
                ]);
                unset($productData['category']);

                $products->push(Product::create($productData));
            }

            // 2. Vehicles
            $vehiclesData = [
                ['name' => 'Tracteur JD 1', 'plate_number' => 'A-12345', 'type' => 'tractor', 'fuel_type' => 'diesel'],
                ['name' => 'Camionnette Ford', 'plate_number' => 'B-67890', 'type' => 'truck', 'fuel_type' => 'diesel'],
                ['name' => 'Voiture de Service', 'plate_number' => 'C-11223', 'type' => 'car', 'fuel_type' => 'gasoline'],
            ];

            $vehicles = collect();
            foreach ($vehiclesData as $data) {
                $driver = $farmEmployees->isNotEmpty() ? $farmEmployees->random() : null;
                $data['plate_number'] .= '-F' . $farm->id;
                $vehicles->push(Vehicle::create(array_merge($data, [
                    'farm_id' => $farm->id,
                    'default_driver_id' => $driver ? $driver->id : null,
                ])));
            }

            // Fournisseurs — a simple named list (no order/procurement workflow, see
            // SupplierController) picked on some réceptions below, alongside a fake N° B.L.
            $suppliersData = ['GDIRAGRI Sarl', 'AgroFournitures Maroc', 'TOP M Nassra', 'Coopérative Semences Nord'];
            $suppliers = collect($suppliersData)->map(
                fn ($name) => Supplier::create(['farm_id' => $farm->id, 'name' => $name])
            );

            // 3. Réceptions — a few stock-in movements per product, at slightly different prices
            // (simulating real supplier price drift), building StockInventory.average_cost the
            // same way StockMovementController::stockIn() does. Every product starts at 0 and is
            // only ever built up through these — nothing is seeded directly into StockInventory.
            $inventories = collect();
            // Sorties below must never be dated before a product's own first réception — stock
            // can't be consumed before it physically arrived. Tracked per product so the sortie
            // loop can pick a date that's always chronologically after it.
            $firstReceptionDateByProduct = [];
            foreach ($products as $product) {
                $inventory = StockInventory::create([
                    'product_id' => $product->id,
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                ]);

                $receptionCount = rand(2, 4);
                for ($r = 0; $r < $receptionCount; $r++) {
                    $qty = rand(200, 800);
                    $unitCost = round($product->unit_cost * (1 + (rand(-10, 10) / 100)), 2);
                    // r=0 is always the furthest-in-the-past réception (90 - 0*20 = 90 days out,
                    // shrinking as r grows) — captured once, before any later/more-recent one.
                    $date = Carbon::now()->subDays(90 - ($r * 20) - rand(0, 10));
                    if ($r === 0) {
                        $firstReceptionDateByProduct[$product->id] = $date->copy();
                    }
                    $performedBy = $farmUsers->isNotEmpty() ? $farmUsers->random() : null;
                    // Most réceptions have a supplier/BL on file — an occasional one doesn't,
                    // so the export/UI's "blank supplier" fallback path stays exercised too.
                    $supplier = rand(0, 4) > 0 ? $suppliers->random() : null;

                    StockMovement::create([
                        'product_id' => $product->id,
                        'supplier_id' => $supplier?->id,
                        'numero_bl' => $supplier ? (string) rand(10000000, 99999999) : null,
                        'movement_type' => 'in',
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                        'total_cost' => $qty * $unitCost,
                        // No reference_type: a réception has no source document to link back to —
                        // see StockMovement::reference() and AppServiceProvider's morph map.
                        'performed_by' => $performedBy ? $performedBy->id : null,
                        'date' => $date,
                        'notes' => "Réception de {$product->name}",
                    ]);

                    $previousQuantity = (float) $inventory->quantity_on_hand;
                    $newQuantity = $previousQuantity + $qty;
                    $inventory->average_cost = $newQuantity > 0
                        ? ((((float) ($inventory->average_cost ?? 0)) * $previousQuantity) + ($unitCost * $qty)) / $newQuantity
                        : $unitCost;
                    $inventory->quantity_on_hand = $newQuantity;
                    $inventory->last_restock_date = $date;
                    $inventory->last_count_date = $date;
                    $inventory->save();
                }

                $inventories->put($product->id, $inventory);
            }

            // 4. Fuel Transactions — draws down Gasoil. About half are attributed to a bloc/opération
            // (a tractor working a field), the rest are plain déplacement (a car/van, no bloc).
            $fuelProduct = $products->where('name', 'Gasoil')->first();
            if ($fuelProduct && $vehicles->isNotEmpty()) {
                $fuelInventory = $inventories->get($fuelProduct->id);

                for ($i = 0; $i < 10; $i++) {
                    $vehicle = $vehicles->random();
                    $isFieldWork = $vehicle->type === 'tractor' && rand(0, 1) === 1;
                    $driver = $farmEmployees->isNotEmpty() ? $farmEmployees->random() : null;
                    $performedBy = $farmUsers->isNotEmpty() ? $farmUsers->random() : null;
                    $quantity = rand(20, 100);
                    $unitPrice = $fuelInventory->average_cost ?? $fuelProduct->unit_cost;
                    $totalCost = $quantity * $unitPrice;
                    $date = Carbon::now()->subDays(rand(1, 60));
                    $bloc = $isFieldWork && $farmBlocs->isNotEmpty() ? $farmBlocs->random() : null;

                    $fuelTransaction = FuelTransaction::create([
                        'farm_id' => $farm->id,
                        'vehicle_id' => $vehicle->id,
                        'product_id' => $fuelProduct->id,
                        'transaction_type' => 'fueling',
                        'quantity_liters' => $quantity,
                        'unit_price_per_liter' => $unitPrice,
                        'total_cost' => $totalCost,
                        'driver_id' => $driver ? $driver->id : null,
                        'performed_by' => $performedBy ? $performedBy->id : null,
                        'date' => $date,
                        'odometer_km' => rand(1000, 50000),
                        'operation_id' => $bloc && $farmOperations->isNotEmpty() ? $farmOperations->random()->id : null,
                        'bloc_id' => $bloc?->id,
                        'notes' => "Ravitaillement pour {$vehicle->name}",
                    ]);

                    StockMovement::create([
                        'product_id' => $fuelProduct->id,
                        'movement_type' => 'out',
                        'quantity' => $quantity,
                        'unit_cost' => $unitPrice,
                        'total_cost' => $totalCost,
                        'reference_type' => 'fuel_transaction',
                        'reference_id' => $fuelTransaction->id,
                        'performed_by' => $performedBy ? $performedBy->id : null,
                        'date' => $date,
                        'notes' => "Fuel transaction for vehicle {$vehicle->name}",
                    ]);

                    $fuelInventory->quantity_on_hand -= $quantity;
                    $fuelInventory->save();
                }
            }

            // 5. Manual Stock Entries (Sorties) — vehicle attached only for fuel/vehicle_needs
            // products, matching what the UI itself allows.
            for ($i = 0; $i < 15; $i++) {
                $product = $products->random();
                $inventory = $inventories->get($product->id);
                $quantity = rand(1, 50);
                $employee = $farmEmployees->isNotEmpty() ? $farmEmployees->random() : null;
                $isVehicleConsumable = (bool) $product->category?->is_vehicle_related;
                $vehicle = $isVehicleConsumable && $vehicles->isNotEmpty() ? $vehicles->random() : null;

                // Secteur/parcelle must actually belong to the chosen bloc — matching the
                // cascading Bloc→Secteur→Parcelle selects in the UI, and mirroring how a real
                // sortie is sometimes logged at just the bloc level, sometimes down to the
                // parcelle.
                $bloc = $farmBlocs->isNotEmpty() ? $farmBlocs->random() : null;
                $blocSectors = $bloc ? $farmSectors->where('bloc_id', $bloc->id) : collect();
                $sector = $blocSectors->isNotEmpty() && rand(0, 1) === 1 ? $blocSectors->random() : null;
                $sectorParcelles = $sector ? $farmParcelles->where('sector_id', $sector->id) : collect();
                $parcelle = $sectorParcelles->isNotEmpty() && rand(0, 1) === 1 ? $sectorParcelles->random() : null;

                $operation = $farmOperations->isNotEmpty() ? $farmOperations->random() : null;
                $enteredBy = $farmUsers->isNotEmpty() ? $farmUsers->random() : null;
                $verifiedBy = $farmUsers->isNotEmpty() ? $farmUsers->random() : null;
                // Must land after this product's own first réception — otherwise the chronological
                // ledger (per-product export's running "Stock" balance) shows stock being consumed
                // before it ever arrived, going negative.
                $firstReceptionDate = $firstReceptionDateByProduct[$product->id];
                $daysSinceFirstReception = max(1, (int) $firstReceptionDate->diffInDays(now()));
                $date = Carbon::now()->subDays(rand(0, $daysSinceFirstReception - 1));
                $unitCost = $inventory->average_cost ?? $product->unit_cost;
                // Varied across the exit types StockExitType self-seeds by default (minus
                // 'maintenance', which needs a real VehicleMaintenanceLog this seeder doesn't
                // create) — and about half left with no note, so the Observations
                // "falls back to the type label" behavior is actually exercised, not just the
                // "real note" path.
                $entryType = collect(['consumption', 'consumption', 'consumption', 'loss', 'theft', 'damage'])->random();
                $note = rand(0, 1) === 1 ? "Consommation de {$product->name}" : null;

                $manualEntry = ManualStockEntry::create([
                    'farm_id' => $farm->id,
                    'product_id' => $product->id,
                    'entry_type' => $entryType,
                    'quantity' => $quantity,
                    'employee_id' => $employee ? $employee->id : null,
                    'vehicle_id' => $vehicle?->id,
                    'operation_id' => $operation ? $operation->id : null,
                    'bloc_id' => $bloc ? $bloc->id : null,
                    'sector_id' => $sector ? $sector->id : null,
                    'parcelle_id' => $parcelle ? $parcelle->id : null,
                    'odometer_km' => $vehicle ? rand(1000, 50000) : null,
                    'date' => $date,
                    'entered_by' => $enteredBy ? $enteredBy->id : null,
                    'notes' => $note,
                    'is_verified' => (rand(0, 1) == 1),
                    'verified_by' => (rand(0, 1) == 1) ? ($verifiedBy ? $verifiedBy->id : null) : null,
                    'verified_at' => (rand(0, 1) == 1) ? Carbon::now()->subDays(rand(0, 10)) : null,
                ]);

                StockMovement::create([
                    'product_id' => $product->id,
                    'movement_type' => 'out',
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $quantity * $unitCost,
                    'reference_type' => 'manual_entry',
                    'reference_id' => $manualEntry->id,
                    'performed_by' => $enteredBy ? $enteredBy->id : null,
                    'date' => $date,
                    // Mirrors ManualStockEntryController::store()'s real behavior: the movement's
                    // own notes IS the sortie's real note (or null) — never a separate string.
                    'notes' => $note,
                ]);

                $inventory->quantity_on_hand -= $quantity;
                $inventory->save();
            }

            // 6. Stock Alerts
            foreach ($products as $product) {
                $inventory = $inventories->get($product->id);
                if ($inventory && $inventory->quantity_on_hand < $product->min_stock_level) {
                    StockAlert::create([
                        'product_id' => $product->id,
                        'alert_type' => 'low_stock',
                        'threshold_value' => $product->min_stock_level,
                        'current_value' => $inventory->quantity_on_hand,
                        'is_resolved' => false,
                        'notes' => "Product {$product->name} is below minimum stock level.",
                    ]);
                }
            }
        }

        $this->command->info('Stock Management Data Seeding Complete!');
    }
}
