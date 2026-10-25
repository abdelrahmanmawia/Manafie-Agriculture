<?php

namespace Tests\Feature\Stock;

use App\Models\Farm;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockAlert;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A fuel consumption sortie is a vehicle's fill-up (FuelTransaction), removable with its liters
 * given back; "stock faible" only against a minimum someone set; the stock lists are paginated
 * and filtered on the server.
 */
class FuelAndStockListsTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $manager;

    private User $magasinier;

    private Product $gasoil;

    private Vehicle $tracteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Test Farm']);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->magasinier = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id]);
        $carburant = ProductCategory::create(['farm_id' => $this->farm->id, 'name' => 'Carburant', 'is_vehicle_related' => true, 'is_carburant' => true]);
        $this->gasoil = Product::create(['farm_id' => $this->farm->id, 'name' => 'GASOIL', 'category_id' => $carburant->id, 'unit_type' => 'liters', 'min_stock_level' => 0, 'unit_cost' => 11, 'is_active' => true]);
        $this->tracteur = Vehicle::create(['farm_id' => $this->farm->id, 'name' => 'Tracteur', 'plate_number' => 'T-1', 'type' => 'tractor', 'fuel_type' => 'diesel', 'is_active' => true]);

        $this->actingAs($this->manager)->post(route('stock.movements.in'), [
            'product_id' => $this->gasoil->id, 'quantity' => 1000, 'unit_cost' => 12, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
    }

    private function plein(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->post(route('stock.manual-entries.store'), array_merge([
            'product_id' => $this->gasoil->id,
            'entry_type' => 'consumption',
            'quantity' => 60,
            'vehicle_id' => $this->tracteur->id,
            'odometer_km' => 1520,
            'date' => now()->toDateString(),
        ], $overrides));
    }

    private function onHand(): float
    {
        return (float) StockInventory::where('product_id', $this->gasoil->id)->value('quantity_on_hand');
    }

    public function test_a_fuel_consumption_sortie_is_recorded_as_the_vehicles_fill_up(): void
    {
        $this->plein($this->magasinier)->assertSessionHasNoErrors();

        $this->assertSame(0, ManualStockEntry::count());
        $fill = FuelTransaction::sole();
        $this->assertSame('fueling', $fill->transaction_type);
        $this->assertSame($this->tracteur->id, $fill->vehicle_id);
        $this->assertEqualsWithDelta(60, $fill->quantity_liters, 0.01);
        $this->assertEqualsWithDelta(720, $fill->total_cost, 0.01); // at the CUMP (12), not the catalog 11
        $this->assertEqualsWithDelta(1520, $fill->odometer_km, 0.01);
        $movement = StockMovement::where('reference_type', 'fuel_transaction')->sole();
        $this->assertSame([$fill->id, 'out'], [$movement->reference_id, $movement->movement_type]);
        $this->assertEqualsWithDelta(940, $this->onHand(), 0.01);
    }

    public function test_a_fill_up_needs_its_vehicle_and_no_more_than_the_magasin_holds(): void
    {
        $this->plein($this->manager, ['vehicle_id' => null])->assertSessionHasErrors('vehicle_id');
        $this->plein($this->manager, ['quantity' => 1001])->assertSessionHasErrors('quantity');

        $this->assertSame(0, FuelTransaction::count());
        $this->assertEqualsWithDelta(1000, $this->onHand(), 0.01);
    }

    public function test_lost_fuel_stays_a_plain_sortie(): void
    {
        $this->plein($this->manager, ['entry_type' => 'loss', 'vehicle_id' => null])->assertSessionHasNoErrors();

        $this->assertSame(1, ManualStockEntry::count());
        $this->assertSame(0, FuelTransaction::count());
    }

    public function test_a_sortie_cannot_be_turned_into_a_fill_up(): void
    {
        $this->plein($this->manager, ['entry_type' => 'loss', 'vehicle_id' => null]);
        $entry = ManualStockEntry::sole();

        $this->actingAs($this->manager)->put(route('stock.manual-entries.update', $entry), ['entry_type' => 'consumption'])
            ->assertSessionHasErrors('product_id');
        $this->assertSame('loss', $entry->fresh()->entry_type);
    }

    public function test_deleting_a_fill_up_gives_its_liters_back_once(): void
    {
        $this->plein($this->magasinier);
        $fill = FuelTransaction::sole();

        $this->actingAs($this->manager)->delete(route('stock.fuel-transactions.destroy', $fill))->assertSessionHas('success');
        $this->actingAs($this->manager)->delete(route('stock.fuel-transactions.destroy', $fill))->assertNotFound();

        $this->assertSame(0, FuelTransaction::count());
        $this->assertSame(0, StockMovement::where('reference_type', 'fuel_transaction')->count());
        $this->assertEqualsWithDelta(1000, $this->onHand(), 0.01);
    }

    public function test_the_magasinier_may_delete_their_own_fill_up_only_the_same_day(): void
    {
        $this->plein($this->magasinier);
        $fill = FuelTransaction::sole();
        $other = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id]);

        $this->actingAs($other)->delete(route('stock.fuel-transactions.destroy', $fill))->assertSessionHas('error');
        Carbon::setTestNow(now()->addDay());
        $this->actingAs($this->magasinier)->delete(route('stock.fuel-transactions.destroy', $fill))->assertSessionHas('error');
        $this->assertSame(1, FuelTransaction::count());

        Carbon::setTestNow();
        $this->actingAs($this->magasinier)->delete(route('stock.fuel-transactions.destroy', $fill))->assertSessionHas('success');
        $this->assertEqualsWithDelta(1000, $this->onHand(), 0.01);
    }

    public function test_a_fill_up_of_another_farm_is_forbidden(): void
    {
        $this->plein($this->manager);
        $otherFarm = Farm::create(['name' => 'Other']);
        $stranger = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $otherFarm->id]);

        $this->actingAs($stranger)->delete(route('stock.fuel-transactions.destroy', FuelTransaction::sole()))->assertForbidden();
        $this->assertSame(1, FuelTransaction::count());
    }

    public function test_a_product_without_a_minimum_is_never_low(): void
    {
        $this->plein($this->manager, ['quantity' => 1000]); // GASOIL has no minimum: down to 0

        $this->assertEqualsWithDelta(0, $this->onHand(), 0.01);
        $this->assertFalse($this->gasoil->fresh()->isLowStock());
        $this->assertSame(0, StockAlert::count());

        $this->actingAs($this->manager)->get(route('stock.inventory.index', ['low_stock' => 1]))
            ->assertInertia(fn (Assert $page) => $page->has('stockInventory', 0));
    }

    public function test_the_stock_lists_are_paginated_and_filtered_by_date(): void
    {
        foreach (range(1, 55) as $day) {
            $this->plein($this->manager, ['quantity' => 1, 'date' => Carbon::parse('2026-08-01')->addDays($day % 30)->toDateString()]);
        }
        $this->plein($this->manager, ['quantity' => 1, 'entry_type' => 'loss', 'vehicle_id' => null, 'date' => '2026-09-15']);

        $this->actingAs($this->manager)->get(route('stock.fuel-transactions.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('fuelTransactions.data', 50)
                ->where('fuelTransactions.total', 55)
                ->where('totals.count', 55));
        $this->actingAs($this->manager)->get(route('stock.fuel-transactions.index', ['from' => '2026-08-02', 'to' => '2026-08-03', 'vehicle_id' => $this->tracteur->id]))
            ->assertInertia(fn (Assert $page) => $page->where('fuelTransactions.total', 4)->where('filters.from', '2026-08-02'));

        // Réception + 55 pleins + 1 perte.
        $this->actingAs($this->manager)->get(route('stock.movements.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('stockMovements.data', 7)->where('stockMovements.total', 57));
        $this->actingAs($this->manager)->get(route('stock.movements.index', ['movement_type' => 'in']))
            ->assertInertia(fn (Assert $page) => $page->where('stockMovements.total', 1));

        $this->actingAs($this->manager)->get(route('stock.manual-entries.index', ['statut' => 'a_verifier', 'from' => '2026-09-01']))
            ->assertInertia(fn (Assert $page) => $page->where('manualStockEntries.total', 1)->where('aVerifier', 1));
        $this->actingAs($this->manager)->get(route('stock.manual-entries.index', ['statut' => 'verifiee']))
            ->assertInertia(fn (Assert $page) => $page->where('manualStockEntries.total', 0));
    }
}
