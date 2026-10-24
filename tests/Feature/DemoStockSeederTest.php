<?php

namespace Tests\Feature;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\Operation;
use App\Models\Product;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\DemoStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The demo stock follows the app's rules: every quantity explained by its movements, never negative. */
class DemoStockSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_stock_is_consistent(): void
    {
        $farm = Farm::create(['name' => 'Persealand']);
        $division = Enterprise::create(['farm_id' => $farm->id, 'name' => 'A', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);
        Bloc::create(['farm_id' => $farm->id, 'name' => 'B1']);
        foreach (['Tr' => 'Tractoristes', 'Irr' => 'Irrigation', 'Tr.F' => 'Traitement Foliaire'] as $abbr => $name) {
            Operation::create(['farm_id' => $farm->id, 'name' => $name, 'abbreviation' => $abbr]);
        }
        Employee::create(['farm_id' => $farm->id, 'enterprise_id' => $division->id, 'matricule' => '001', 'full_name' => 'W', 'base_rate' => 97.44, 'is_active' => true]);

        $this->seed(DemoStockSeeder::class);

        $this->assertGreaterThan(50, ManualStockEntry::count());
        $this->assertGreaterThan(50, FuelTransaction::count());
        foreach (Product::with('stockInventory')->get() as $product) {
            $onHand = (float) $product->stockInventory->first()->quantity_on_hand;
            $moves = StockMovement::where('product_id', $product->id)->get();
            $explained = $moves->where('movement_type', 'in')->sum('quantity') + $moves->where('movement_type', 'adjustment')->sum('quantity')
                - $moves->where('movement_type', 'out')->sum('quantity');
            $this->assertEqualsWithDelta($onHand, $explained, 0.01, $product->name);
            $this->assertGreaterThanOrEqual(0, $onHand, $product->name);
        }
        $this->assertSame(0, ManualStockEntry::whereNotIn('id', StockMovement::where('reference_type', 'manual_entry')->pluck('reference_id'))->count());
        $this->assertSame(0, FuelTransaction::whereNotIn('id', StockMovement::where('reference_type', 'fuel_transaction')->pluck('reference_id'))->count());
        $this->assertSame(StockInventory::count(), Product::count());
    }
}
