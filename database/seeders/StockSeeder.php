<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Farm;
use App\Models\Product;
use App\Models\Vehicle;
use App\Models\StockInventory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\StockAlert;
use App\Models\StockMovement;
use App\Models\Employee;
use App\Models\Bloc;
use App\Models\Sector;
use App\Models\Parcelle;
use App\Models\Operation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StockSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding Stock Management Data...');

        // Disable foreign key checks for truncate (SQLite compatible)
        DB::statement('PRAGMA foreign_keys = OFF;');

        Product::truncate();
        Vehicle::truncate();
        StockInventory::truncate();
        PurchaseOrder::truncate();
        PurchaseOrderItem::truncate();
        FuelTransaction::truncate();
        ManualStockEntry::truncate();
        StockAlert::truncate();
        StockMovement::truncate();

        // Enable foreign key checks after truncate (SQLite compatible)
        DB::statement('PRAGMA foreign_keys = ON;');

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


            // 1. Products
            $productsData = [
                ['name' => 'Semences de Tomate', 'reference_code' => 'TOM-SEM-001', 'category' => 'seeds', 'unit_type' => 'units', 'min_stock_level' => 500, 'unit_cost' => 0.15],
                ['name' => 'Engrais NPK 15-15-15', 'reference_code' => 'ENG-NPK-001', 'category' => 'fertilizers', 'unit_type' => 'kg', 'min_stock_level' => 1000, 'unit_cost' => 12.50],
                ['name' => 'Insecticide Bio', 'reference_code' => 'INS-BIO-001', 'category' => 'pesticides', 'unit_type' => 'liters', 'min_stock_level' => 50, 'unit_cost' => 85.00],
                ['name' => 'Petite Pelle', 'reference_code' => 'OUT-PEL-001', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 10, 'unit_cost' => 45.00],
                ['name' => 'Sacs de 25kg', 'reference_code' => 'EMB-SAC-001', 'category' => 'packaging', 'unit_type' => 'units', 'min_stock_level' => 200, 'unit_cost' => 2.00],
                ['name' => 'Tracteur John Deere', 'reference_code' => 'EQU-JOH-001', 'category' => 'equipment', 'unit_type' => 'units', 'min_stock_level' => 1, 'unit_cost' => 500000.00],
                ['name' => 'Gasoil', 'reference_code' => 'CAR-GAS-001', 'category' => 'fuel', 'unit_type' => 'liters', 'min_stock_level' => 500, 'unit_cost' => 13.00],
                ['name' => 'Huile Moteur', 'reference_code' => 'AUT-HUI-001', 'category' => 'other', 'unit_type' => 'liters', 'min_stock_level' => 20, 'unit_cost' => 60.00],
            ];

            $products = collect();
            foreach ($productsData as $data) {
                $products->push(Product::create(array_merge($data, ['farm_id' => $farm->id])));
            }

            // 2. Vehicles
            $vehiclesData = [
                ['name' => 'Tracteur JD 1', 'plate_number' => 'A-12345', 'type' => 'tractor', 'fuel_type' => 'diesel', 'fuel_capacity_liters' => 150],
                ['name' => 'Camionnette Ford', 'plate_number' => 'B-67890', 'type' => 'truck', 'fuel_type' => 'diesel', 'fuel_capacity_liters' => 80],
                ['name' => 'Voiture de Service', 'plate_number' => 'C-11223', 'type' => 'car', 'fuel_type' => 'gasoline', 'fuel_capacity_liters' => 50],
            ];

            $vehicles = collect();
            foreach ($vehiclesData as $data) {
                $driver = $farmEmployees->isNotEmpty() ? $farmEmployees->random() : null;
                $vehicles->push(Vehicle::create(array_merge($data, [
                    'farm_id' => $farm->id,
                    'default_driver_id' => $driver ? $driver->id : null,
                ])));
            }

            // 3. Stock Inventory (Initial Stock)
            foreach ($products as $product) {
                $initialQuantity = rand(100, 2000);
                StockInventory::create([
                    'product_id' => $product->id,
                    'quantity_on_hand' => $initialQuantity,
                    'quantity_reserved' => 0,
                    'last_restock_date' => Carbon::now()->subDays(rand(1, 60)),
                    'last_count_date' => Carbon::now()->subDays(rand(1, 30)),
                    'average_cost' => $product->unit_cost,
                ]);
            }

            // 4. Purchase Orders
            $supplierNames = ['AgriSupply Co.', 'Fertilizer King', 'Tools R Us'];
            $poStatuses = ['pending', 'ordered', 'received', 'partial'];

            for ($i = 0; $i < 5; $i++) {
                $orderDate = Carbon::now()->subDays(rand(1, 90));
                $expectedDate = (clone $orderDate)->addDays(rand(5, 30));
                $status = $poStatuses[array_rand($poStatuses)];
                $receivedBy = $farmUsers->isNotEmpty() ? $farmUsers->random() : null;

                $purchaseOrder = PurchaseOrder::create([
                    'farm_id' => $farm->id,
                    'order_number' => 'PO-' . $farm->id . '-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                    'supplier_name' => $supplierNames[array_rand($supplierNames)],
                    'order_date' => $orderDate,
                    'expected_date' => $expectedDate,
                    'status' => $status,
                    'total_amount' => 0,
                    'notes' => 'Sample purchase order ' . ($i + 1),
                    'received_by' => $status == 'received' || $status == 'partial' ? ($receivedBy ? $receivedBy->id : null) : null,
                    'received_at' => $status == 'received' || $status == 'partial' ? Carbon::now()->subDays(rand(0, 10)) : null,
                ]);

                $totalAmount = 0;
                $numItems = rand(1, 3);
                $selectedProducts = $products->random($numItems);

                foreach ($selectedProducts as $product) {
                    $quantityOrdered = rand(10, 500);
                    $unitPrice = $product->unit_cost * (1 + (rand(-5, 5) / 100)); // +/- 5% variation
                    $totalPrice = $quantityOrdered * $unitPrice;
                    $quantityReceived = 0;

                    if ($status == 'received') {
                        $quantityReceived = $quantityOrdered;
                    } elseif ($status == 'partial') {
                        $quantityReceived = rand(1, $quantityOrdered - 1);
                    }

                    PurchaseOrderItem::create([
                        'purchase_order_id' => $purchaseOrder->id,
                        'product_id' => $product->id,
                        'quantity_ordered' => $quantityOrdered,
                        'quantity_received' => $quantityReceived,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                        'received_date' => $quantityReceived > 0 ? Carbon::now()->subDays(rand(0, 10)) : null,
                        'batch_number' => 'BATCH-' . rand(100, 999),
                    ]);
                    $totalAmount += $totalPrice;

                    // Simulate stock movement and inventory update for received items
                    if ($quantityReceived > 0) {
                        StockMovement::create([
                            'product_id' => $product->id,
                            'movement_type' => 'in',
                            'quantity' => $quantityReceived,
                            'unit_cost' => $unitPrice,
                            'total_cost' => $quantityReceived * $unitPrice,
                            'reference_type' => 'purchase_order',
                            'reference_id' => $purchaseOrder->id,
                            'performed_by' => $receivedBy ? $receivedBy->id : null,
                            'date' => $purchaseOrder->received_at ?? Carbon::now(),
                            'notes' => "Received from PO {$purchaseOrder->order_number}",
                        ]);

                        $inventory = StockInventory::firstOrCreate(['product_id' => $product->id]);
                        $inventory->quantity_on_hand += $quantityReceived;
                        $inventory->last_restock_date = $purchaseOrder->received_at ?? Carbon::now();
                        $inventory->save();
                    }
                }
                $purchaseOrder->update(['total_amount' => $totalAmount]);
            }

            // 5. Fuel Transactions
            $fuelProduct = $products->where('category', 'fuel')->first();
            if ($fuelProduct && $vehicles->isNotEmpty()) {
                for ($i = 0; $i < 10; $i++) {
                    $vehicle = $vehicles->random();
                    $driver = $farmEmployees->isNotEmpty() ? $farmEmployees->random() : null;
                    $performedBy = $farmUsers->isNotEmpty() ? $farmUsers->random() : null;
                    $quantity = rand(20, 100);
                    $unitPrice = $fuelProduct->unit_cost * (1 + (rand(-2, 2) / 100));
                    $totalCost = $quantity * $unitPrice;
                    $date = Carbon::now()->subDays(rand(1, 60));

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
                        'hours_worked' => rand(10, 500),
                        'notes' => "Fueling for {$vehicle->name}",
                    ]);

                    // Simulate stock movement for fuel consumption
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
                        'notes' => "Fuel transaction for vehicle " . (optional($vehicle)->name ?? 'N/A'),
                        'vehicle_id' => $vehicle->id,
                    ]);

                    $inventory = StockInventory::firstOrCreate(['product_id' => $fuelProduct->id]);
                    $inventory->quantity_on_hand -= $quantity;
                    $inventory->save();
                }
            }

            // 6. Manual Stock Entries (Consumption)
            for ($i = 0; $i < 15; $i++) {
                $product = $products->random();
                $quantity = rand(1, 50);
                $employee = $farmEmployees->isNotEmpty() ? $farmEmployees->random() : null;
                $vehicle = $vehicles->isNotEmpty() ? $vehicles->random() : null;
                $bloc = $farmBlocs->isNotEmpty() ? $farmBlocs->random() : null;
                $sector = $farmSectors->isNotEmpty() ? $farmSectors->random() : null;
                $parcelle = $farmParcelles->isNotEmpty() ? $farmParcelles->random() : null;
                $operation = $farmOperations->isNotEmpty() ? $farmOperations->random() : null;
                $enteredBy = $farmUsers->isNotEmpty() ? $farmUsers->random() : null;
                $verifiedBy = $farmUsers->isNotEmpty() ? $farmUsers->random() : null;
                $date = Carbon::now()->subDays(rand(1, 90));

                $manualEntry = ManualStockEntry::create([
                    'farm_id' => $farm->id,
                    'product_id' => $product->id,
                    'entry_type' => 'consumption',
                    'quantity' => $quantity,
                    'employee_id' => $employee ? $employee->id : null,
                    'vehicle_id' => $vehicle ? $vehicle->id : null,
                    'operation_id' => $operation ? $operation->id : null,
                    'bloc_id' => $bloc ? $bloc->id : null,
                    'sector_id' => $sector ? $sector->id : null,
                    'parcelle_id' => $parcelle ? $parcelle->id : null,
                    'date' => $date,
                    'entered_by' => $enteredBy ? $enteredBy->id : null,
                    'notes' => "Manual consumption for {$product->name}",
                    'is_verified' => (rand(0, 1) == 1),
                    'verified_by' => (rand(0, 1) == 1) ? ($verifiedBy ? $verifiedBy->id : null) : null,
                    'verified_at' => (rand(0, 1) == 1) ? Carbon::now()->subDays(rand(0, 10)) : null,
                ]);

                // Simulate stock movement for manual consumption
                StockMovement::create([
                    'product_id' => $product->id,
                    'movement_type' => 'out',
                    'quantity' => $quantity,
                    'unit_cost' => $product->unit_cost,
                    'total_cost' => $quantity * $product->unit_cost,
                    'reference_type' => 'manual_entry',
                    'reference_id' => $manualEntry->id,
                    'performed_by' => $enteredBy ? $enteredBy->id : null,
                    'date' => $date,
                    'notes' => "Manual consumption for {$product->name}",
                    'bloc_id' => $bloc ? $bloc->id : null,
                    'sector_id' => $sector ? $sector->id : null,
                    'parcelle_id' => $parcelle ? $parcelle->id : null,
                    'vehicle_id' => $vehicle ? $vehicle->id : null,
                ]);

                $inventory = StockInventory::firstOrCreate(['product_id' => $product->id]);
                $inventory->quantity_on_hand -= $quantity;
                $inventory->save();
            }

            // 7. Stock Alerts
            foreach ($products as $product) {
                $inventory = StockInventory::where('product_id', $product->id)->first();
                if ($inventory) {
                    // Low stock alert
                    if ($inventory->quantity_on_hand < $product->min_stock_level) {
                        StockAlert::create([
                            'product_id' => $product->id,
                            'alert_type' => 'low_stock',
                            'threshold_value' => $product->min_stock_level,
                            'current_value' => $inventory->quantity_on_hand,
                            'is_resolved' => false,
                            'notes' => "Product {$product->name} is below minimum stock level.",
                        ]);
                    }
                    // Expiring soon/expired (if product has expiry_date)
                    // Note: Product model does not currently have an expiry_date field in the design.
                    // This part will not create alerts unless expiry_date is added to Product model.
                    /*
                    if ($product->expiry_date) {
                        $daysUntilExpiry = Carbon::now()->diffInDays($product->expiry_date, false);
                        if ($daysUntilExpiry <= 0) {
                            StockAlert::create([
                                'product_id' => $product->id,
                                'alert_type' => 'expired',
                                'threshold_value' => 0,
                                'current_value' => $inventory->quantity_on_hand,
                                'is_resolved' => false,
                                'notes' => "Product {$product->name} has expired.",
                            ]);
                        } elseif ($daysUntilExpiry <= 30) {
                            StockAlert::create([
                                'product_id' => $product->id,
                                'alert_type' => 'expiring_soon',
                                'threshold_value' => 30,
                                'current_value' => $daysUntilExpiry,
                                'is_resolved' => false,
                                'notes' => "Product {$product->name} is expiring soon.",
                            ]);
                        }
                    }
                    */
                }
            }
        }

        $this->command->info('Stock Management Data Seeding Complete!');
    }
}
