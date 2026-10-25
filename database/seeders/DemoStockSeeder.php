<?php

namespace Database\Seeders;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\Operation;
use App\Models\Parcelle;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceLog;
use App\Models\VehicleUsage;
use App\Services\StockAlertService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for the whole Stock module, to see it full while testing — NOT real data.
 *
 * Starts from the farm's real magasin + matériel (StockRealDataSeeder, which wipes the farm's
 * stock first), adds the consumables the farm buys (gasoil, engrais, pesticides, huiles…), prices
 * and minimum levels, suppliers, then replays the last 90 days day by day with the app's own
 * rules: réceptions raise the stock and its weighted-average cost (CUMP), sorties and fuelings
 * take stock out at that cost and never more than is on hand, each one paired with its
 * StockMovement; maintenance work with its spare parts, rented machines' days, a couple of
 * inventory count adjustments, and the low-stock alerts at the end. Seeded random, so a re-run
 * gives the same data.
 *
 *   php artisan db:seed --class=DemoStockSeeder
 */
class DemoStockSeeder extends Seeder
{
    private const DAYS = 90;

    private Farm $farm;

    private int $userId;

    private int $magasinierId;

    /** [name, category, unit, unit_cost, min_stock_level, opening quantity, supplier, restock quantity, operations] */
    private const CONSOMMABLES = [
        ['GASOIL', 'fuel', 'liters', 11.40, 1500, 3000, 'AFRIQUIA SMDC', 4000, ['Tr', 'T.P']],
        ['ESSENCE SANS PLOMB', 'fuel', 'liters', 13.20, 150, 300, 'AFRIQUIA SMDC', 400, ['T.P']],
        ['HUILE MOTEUR 15W40', 'vehicle_needs', 'liters', 46.00, 40, 80, 'GARAGE AGRI SOUSS', 100, ['Tr']],
        ['HUILE HYDRAULIQUE', 'vehicle_needs', 'liters', 38.00, 40, 60, 'GARAGE AGRI SOUSS', 100, ['Tr']],
        ['FILTRE A HUILE', 'vehicle_needs', 'units', 85.00, 6, 12, 'GARAGE AGRI SOUSS', 12, ['Tr']],
        ['FILTRE A GASOIL', 'vehicle_needs', 'units', 95.00, 6, 10, 'GARAGE AGRI SOUSS', 12, ['Tr']],
        ['GRAISSE', 'vehicle_needs', 'kg', 42.00, 10, 25, 'GARAGE AGRI SOUSS', 25, ['Tr']],
        ['NPK 15-15-15', 'fertilizers', 'kg', 7.60, 1000, 2500, 'ATLAS ENGRAIS', 3000, ['Irr', 'Ep.F']],
        ['UREE 46%', 'fertilizers', 'kg', 6.90, 800, 2000, 'ATLAS ENGRAIS', 2500, ['Irr']],
        ['SULFATE DE POTASSE', 'fertilizers', 'kg', 9.30, 600, 1500, 'ATLAS ENGRAIS', 2000, ['Irr']],
        ['NITRATE DE CALCIUM', 'fertilizers', 'kg', 8.40, 600, 1200, 'ATLAS ENGRAIS', 1500, ['Irr']],
        ['ACIDE PHOSPHORIQUE', 'fertilizers', 'liters', 14.50, 200, 400, 'ATLAS ENGRAIS', 600, ['Irr']],
        ['GLYPHOSATE 360', 'pesticides', 'liters', 74.00, 40, 120, 'PHYTO MAROC', 120, ['D.Ch']],
        ['HYDROXYDE DE CUIVRE', 'pesticides', 'kg', 96.00, 30, 80, 'PHYTO MAROC', 80, ['Tr.F']],
        ['ABAMECTINE', 'pesticides', 'liters', 320.00, 5, 12, 'PHYTO MAROC', 15, ['Tr.F']],
        ['HUILE BLANCHE', 'pesticides', 'liters', 28.00, 60, 150, 'PHYTO MAROC', 200, ['Tr.F', 'Bru']],
        ['CAISSE PLASTIQUE 20KG', 'packaging', 'units', 45.00, 200, 400, 'EMBALLAGE DU SUD', 500, ['Réc']],
        ['SAC PP 50KG', 'packaging', 'bags', 3.20, 300, 600, 'EMBALLAGE DU SUD', 1000, ['Réc', 'CHA']],
        ['GOUTTEUR 4L/H', 'tools', 'units', 0.90, 1000, 3000, 'QUINCAILLERIE EL AMAL', 5000, ['F.G', 'R.F']],
        ['TUYAU PE 16MM (ROULEAU 400M)', 'tools', 'rolls', 380.00, 4, 10, 'QUINCAILLERIE EL AMAL', 10, ['R.F', 'Irr']],
    ];

    private const LABELS = [
        'fertilizers' => 'Engrais', 'pesticides' => 'Pesticides', 'tools' => 'Outils', 'packaging' => 'Emballage',
        'fuel' => 'Carburant', 'vehicle_needs' => 'Besoins Véhicule',
    ];

    public function run(): void
    {
        mt_srand(2026);

        $this->call(StockRealDataSeeder::class);

        $this->farm = Farm::where('name', 'Persealand')->firstOrFail();
        $users = User::where('farm_id', $this->farm->id)->orWhere('role', 'super_admin')->get();
        $this->userId = ($users->firstWhere('role', 'farm_manager') ?? $users->first())->id;
        $this->magasinierId = ($users->first(fn ($u) => stripos($u->name, 'magasin') !== false) ?? $users->first())->id;

        DB::transaction(function () {
            $this->pricesAndMinimums();
            [$consommables, $suppliers] = $this->consommables();
            $this->replay($consommables, $suppliers);
            // The products with a minimum level to watch (the app checks a product when its stock moves).
            Product::where('farm_id', $this->farm->id)->where('min_stock_level', '>', 0)->get()->each(fn ($p) => StockAlertService::syncLowStock($p));
        });

        $this->command->info(sprintf(
            'Démo : %d réceptions, %d sorties, %d ravitaillements, %d interventions, %d jours de location, %d alertes.',
            StockMovement::where('movement_type', 'in')->count(), ManualStockEntry::count(), FuelTransaction::count(),
            VehicleMaintenanceLog::count(), VehicleUsage::count(), \App\Models\StockAlert::where('is_resolved', false)->count(),
        ));
    }

    /** The real inventory's products get a price and, for a third of them, a minimum level. */
    private function pricesAndMinimums(): void
    {
        foreach (Product::where('farm_id', $this->farm->id)->with('stockInventory')->get() as $product) {
            $cost = round(mt_rand(500, 45000) / 100, 2);
            $onHand = (float) ($product->stockInventory->first()?->quantity_on_hand ?? 0);
            $product->update([
                'unit_cost' => $cost,
                // A minimum only where there is stock to watch; around it, so a few dip under.
                'min_stock_level' => $onHand > 0 && mt_rand(1, 3) === 1 ? max(2, (int) round($onHand * mt_rand(50, 120) / 100)) : 0,
            ]);
            $product->stockInventory->first()?->update(['average_cost' => $cost, 'last_count_date' => now()->subDays(self::DAYS)->toDateString()]);
        }
    }

    /** The bought consumables, each with an opening stock 90 days ago and its supplier. */
    private function consommables(): array
    {
        $suppliers = collect(self::CONSOMMABLES)->pluck(6)->unique()
            ->mapWithKeys(fn ($name) => [$name => Supplier::firstOrCreate(['farm_id' => $this->farm->id, 'name' => $name], ['is_active' => true])]);

        $opening = now()->subDays(self::DAYS)->toDateString();
        $consommables = [];
        foreach (self::CONSOMMABLES as [$name, $category, $unit, $cost, $min, $qty, $supplier, $restock, $ops]) {
            $product = Product::create([
                'farm_id' => $this->farm->id, 'name' => $name, 'unit_type' => $unit, 'unit_cost' => $cost, 'min_stock_level' => $min, 'is_active' => true,
                'category_id' => ProductCategory::firstOrCreate(['farm_id' => $this->farm->id, 'name' => self::LABELS[$category]],
                    ['is_vehicle_related' => in_array($category, ['fuel', 'vehicle_needs'], true), 'is_carburant' => $category === 'fuel'])->id,
            ]);
            StockInventory::create(['product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0, 'average_cost' => $cost]);
            $this->reception($product, $qty, $cost, $suppliers[$supplier], $opening, 'Stock de départ (démo)');
            $consommables[$name] = compact('product', 'cost', 'min', 'restock', 'ops') + ['supplier' => $suppliers[$supplier]];
        }

        return [$consommables, $suppliers];
    }

    private function replay(array $consommables, $suppliers): void
    {
        $operations = Operation::where('farm_id', $this->farm->id)->get()->keyBy('abbreviation');
        $parcelles = Parcelle::whereIn('bloc_id', Bloc::where('farm_id', $this->farm->id)->pluck('id'))->get();
        $blocs = Bloc::where('farm_id', $this->farm->id)->get();
        $workers = Employee::where('farm_id', $this->farm->id)->where('is_active', true)->pluck('id')->all();
        $drivers = Employee::where('farm_id', $this->farm->id)->where('is_active', true)->inRandomOrder()->limit(6)->pluck('id')->all();
        $engines = Vehicle::where('farm_id', $this->farm->id)->where('asset_type', 'vehicle')->where('status', 'operational')->get();
        $tractors = $engines->where('type', 'tractor')->values();
        $odometer = $engines->mapWithKeys(fn ($v) => [$v->id => $v->type === 'tractor' ? mt_rand(2000, 9000) : mt_rand(40000, 120000)])->all();
        $spareParts = Product::where('farm_id', $this->farm->id)->whereHas('stockInventory', fn ($q) => $q->where('quantity_on_hand', '>', 0))
            ->whereNotIn('name', array_keys($consommables))->get();
        $rented = Vehicle::where('farm_id', $this->farm->id)->where('is_location', true)->get();

        $place = function () use ($parcelles, $blocs) {
            $parcelle = $parcelles->isNotEmpty() ? $parcelles->random() : null;

            return $parcelle
                ? ['bloc_id' => $parcelle->bloc_id, 'sector_id' => $parcelle->sector_id, 'parcelle_id' => $parcelle->id]
                : ['bloc_id' => $blocs->random()->id, 'sector_id' => null, 'parcelle_id' => null];
        };
        $operation = fn (array $abbrs) => $operations->only($abbrs)->values()->whenNotEmpty(fn ($c) => $c->random())->first()?->id ?? $operations->random()->id;

        for ($d = self::DAYS - 1; $d >= 0; $d--) {
            $day = now()->subDays($d)->startOfDay();
            if ($day->isSunday()) {
                continue;
            }
            $date = $day->toDateString();

            // Réceptions: whatever fell under its minimum gets reordered (a few days' delay).
            foreach ($consommables as $c) {
                $onHand = (float) $c['product']->stockInventory()->value('quantity_on_hand');
                if ($onHand < $c['min'] * 1.2 && mt_rand(1, 3) === 1) {
                    $price = round($c['cost'] * mt_rand(95, 108) / 100, 2);
                    $this->reception($c['product'], $c['restock'], $price, $c['supplier'], $date, null);
                }
            }

            // Fuelings: most tractors every working day, a car every other day.
            foreach ($engines as $vehicle) {
                if ($vehicle->type === 'tractor' ? mt_rand(1, 10) > 3 : mt_rand(1, 2) === 1) {
                    $product = $vehicle->type === 'quad' ? $consommables['ESSENCE SANS PLOMB']['product'] : $consommables['GASOIL']['product'];
                    $liters = $vehicle->type === 'tractor' ? mt_rand(35, 90) : mt_rand(20, 45);
                    $odometer[$vehicle->id] += $vehicle->type === 'tractor' ? mt_rand(4, 9) : mt_rand(60, 220);
                    $this->fueling($product, $vehicle, $liters, $drivers ? $drivers[array_rand($drivers)] : null, $date, $odometer[$vehicle->id],
                        $operation(['Tr', 'T.P']), $place());
                }
            }

            // Sorties to the field: 2 to 6 a day, fertilizers / treatments / irrigation parts / packaging.
            $field = array_filter($consommables, fn ($c, $name) => ! in_array($name, ['GASOIL', 'ESSENCE SANS PLOMB'], true), ARRAY_FILTER_USE_BOTH);
            for ($i = mt_rand(2, 6); $i > 0; $i--) {
                $c = $field[array_rand($field)];
                $unit = $c['product']->unit_type;
                $qty = match ($unit) {
                    'kg' => mt_rand(25, 250), 'liters' => mt_rand(5, 40), 'bags' => mt_rand(20, 80), 'rolls' => 1,
                    default => mt_rand(5, 60),
                };
                $type = mt_rand(1, 40) === 1 ? ['loss', 'damage'][mt_rand(0, 1)] : 'consumption';
                $this->sortie($c['product'], $qty, $type, $date, $place() + [
                    'operation_id' => $operation($c['ops']),
                    'employee_id' => $workers ? $workers[array_rand($workers)] : null,
                ]);
            }

            // A real magasin part now and then (flanges, elbows… from the counted inventory).
            if ($spareParts->isNotEmpty() && mt_rand(1, 3) === 1) {
                $this->sortie($spareParts->random(), mt_rand(1, 4), 'consumption', $date, $place() + [
                    'operation_id' => $operation(['R.F', 'F.G', 'M.P', 'En.P']),
                    'employee_id' => $workers ? $workers[array_rand($workers)] : null,
                ]);
            }

            // Maintenance: about once a week, a vidange or a repair with its parts.
            if ($tractors->isNotEmpty() && mt_rand(1, 6) === 1) {
                $vehicle = $tractors->random();
                $vidange = mt_rand(1, 2) === 1;
                $log = VehicleMaintenanceLog::create([
                    'vehicle_id' => $vehicle->id, 'farm_id' => $this->farm->id,
                    'description' => $vidange ? 'Vidange + filtres' : ['Réparation embrayage', 'Changement pneu arrière', 'Réparation circuit hydraulique', 'Remplacement batterie'][mt_rand(0, 3)],
                    'performed_at' => $date, 'cost' => $vidange ? mt_rand(300, 600) : mt_rand(800, 4500),
                    'performed_by_id' => $drivers ? $drivers[array_rand($drivers)] : null,
                    'next_due_date' => $vidange ? $day->copy()->addDays(90)->toDateString() : null,
                    'created_by' => $this->userId,
                ]);
                $parts = $vidange
                    ? [['HUILE MOTEUR 15W40', 12], ['FILTRE A HUILE', 1], ['FILTRE A GASOIL', 1]]
                    : [['HUILE HYDRAULIQUE', mt_rand(5, 20)], ['GRAISSE', mt_rand(1, 3)]];
                foreach ($parts as [$name, $qty]) {
                    $this->sortie($consommables[$name]['product'], $qty, 'maintenance', $date,
                        ['vehicle_id' => $vehicle->id, 'maintenance_log_id' => $log->id, 'employee_id' => $log->performed_by_id]);
                }
            }

            // Rented machines are paid by the day they work.
            foreach ($rented as $vehicle) {
                if (mt_rand(1, 10) > 2) {
                    VehicleUsage::create(['vehicle_id' => $vehicle->id, 'farm_id' => $this->farm->id, 'date' => $date, 'daily_rate' => $vehicle->default_daily_rate ?: 900]);
                }
            }

            // Two inventory counts in the period: the count corrects the book stock.
            if (in_array($d, [60, 20], true)) {
                foreach (collect($consommables)->random(4) as $c) {
                    $this->adjustment($c['product'], mt_rand(-15, 5), $date);
                }
            }
        }
    }

    private function reception(Product $product, float $qty, float $price, Supplier $supplier, string $date, ?string $notes): void
    {
        StockMovement::create([
            'product_id' => $product->id, 'supplier_id' => $supplier->id, 'numero_bl' => 'BL-'.Carbon::parse($date)->format('ymd').'-'.mt_rand(100, 999),
            'movement_type' => 'in', 'quantity' => $qty, 'unit_cost' => $price, 'total_cost' => round($price * $qty, 2),
            'performed_by' => $this->magasinierId, 'date' => $date, 'notes' => $notes,
        ]);
        $inventory = StockInventory::where('product_id', $product->id)->first();
        $before = (float) $inventory->quantity_on_hand;
        $after = $before + $qty;
        $inventory->update([
            'average_cost' => $after > 0 ? ((float) $inventory->average_cost * $before + $price * $qty) / $after : $price,
            'quantity_on_hand' => $after,
            'last_restock_date' => $date,
        ]);
    }

    /** A sortie, never more than is on hand (skipped when the magasin is empty). */
    private function sortie(Product $product, float $qty, string $type, string $date, array $fields): void
    {
        $inventory = StockInventory::where('product_id', $product->id)->first();
        $qty = min($qty, (float) $inventory->quantity_on_hand);
        if ($qty <= 0) {
            return;
        }
        $cost = (float) ($inventory->average_cost ?? $product->unit_cost ?? 0);
        $entry = ManualStockEntry::create($fields + [
            'farm_id' => $this->farm->id, 'product_id' => $product->id, 'entry_type' => $type, 'quantity' => $qty, 'date' => $date,
            'entered_by' => $this->magasinierId,
            'notes' => $type === 'loss' ? 'Sac déchiré au déchargement' : ($type === 'damage' ? 'Produit abîmé (humidité)' : null),
        ]);
        StockMovement::create([
            'product_id' => $product->id, 'movement_type' => 'out', 'quantity' => $qty, 'unit_cost' => $cost, 'total_cost' => round($cost * $qty, 2),
            'reference_type' => 'manual_entry', 'reference_id' => $entry->id, 'performed_by' => $this->magasinierId, 'date' => $date,
        ]);
        $inventory->update(['quantity_on_hand' => (float) $inventory->quantity_on_hand - $qty]);
    }

    private function fueling(Product $product, Vehicle $vehicle, float $liters, ?int $driverId, string $date, int $odometer, int $operationId, array $place): void
    {
        $inventory = StockInventory::where('product_id', $product->id)->first();
        $liters = min($liters, (float) $inventory->quantity_on_hand);
        if ($liters <= 0) {
            return;
        }
        $price = round((float) $inventory->average_cost, 2);
        $transaction = FuelTransaction::create($place + [
            'farm_id' => $this->farm->id, 'vehicle_id' => $vehicle->id, 'product_id' => $product->id, 'transaction_type' => 'fueling',
            'quantity_liters' => $liters, 'unit_price_per_liter' => $price, 'total_cost' => round($price * $liters, 2),
            'driver_id' => $driverId, 'performed_by' => $this->magasinierId, 'date' => $date, 'odometer_km' => $odometer, 'operation_id' => $operationId,
        ]);
        StockMovement::create([
            'product_id' => $product->id, 'movement_type' => 'out', 'quantity' => $liters, 'unit_cost' => $price, 'total_cost' => round($price * $liters, 2),
            'reference_type' => 'fuel_transaction', 'reference_id' => $transaction->id, 'performed_by' => $this->magasinierId, 'date' => $date,
        ]);
        $inventory->update(['quantity_on_hand' => (float) $inventory->quantity_on_hand - $liters]);
    }

    private function adjustment(Product $product, float $delta, string $date): void
    {
        $inventory = StockInventory::where('product_id', $product->id)->first();
        $delta = max($delta, -(float) $inventory->quantity_on_hand);
        if ($delta == 0) {
            return;
        }
        // Same record as StockInventoryController::count(): the signed difference and what was counted.
        $before = (float) $inventory->quantity_on_hand;
        StockMovement::create([
            'product_id' => $product->id, 'movement_type' => 'adjustment', 'quantity' => $delta,
            'unit_cost' => $product->unit_cost, 'total_cost' => round((float) $product->unit_cost * $delta, 2),
            'performed_by' => $this->userId, 'date' => $date,
            'notes' => 'Comptage de stock. Précédent : '.$before.', Compté : '.($before + $delta),
        ]);
        $inventory->update(['quantity_on_hand' => $before + $delta, 'last_count_date' => $date]);
    }
}
