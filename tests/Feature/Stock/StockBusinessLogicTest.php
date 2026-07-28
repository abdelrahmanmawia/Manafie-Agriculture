<?php

namespace Tests\Feature\Stock;

use App\Models\Bloc;
use App\Models\Farm;
use App\Models\Parcelle;
use App\Models\Product;
use App\Models\Sector;
use App\Models\StockAlert;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the core Stock calculation/validation rules fixed earlier: CUMP (weighted-average
 * cost) on réception, the negative-stock guard on sortie, automatic low-stock alert sync, and
 * bloc/secteur/parcelle hierarchy validation. `store()`/`stockIn()` require a non-data_entry
 * role (see StockDataEntryRoleTest), so these use a farm_manager throughout.
 */
class StockBusinessLogicTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;
    private User $farmManager;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Test Farm']);

        $this->farmManager = User::factory()->create([
            'role' => 'farm_manager',
            'farm_id' => $this->farm->id,
        ]);

        $this->product = Product::create([
            'farm_id' => $this->farm->id,
            'name' => 'Engrais Test',
            'category' => 'fertilizers',
            'unit_type' => 'kg',
            'min_stock_level' => 100,
            'unit_cost' => 20, // catalog price — should never drive movement cost once CUMP exists
            'is_active' => true,
        ]);
    }

    private function receive(float $quantity, float $unitCost): void
    {
        $this->actingAs($this->farmManager)->post(route('stock.movements.in'), [
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'date' => now()->toDateString(),
        ])->assertRedirect();
    }

    private function sortie(float $quantity, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->farmManager)->post(route('stock.manual-entries.store'), array_merge([
            'product_id' => $this->product->id,
            'entry_type' => 'consumption',
            'quantity' => $quantity,
            'date' => now()->toDateString(),
        ], $overrides));
    }

    // --- CUMP (weighted-average cost) ---

    public function test_first_reception_sets_average_cost_to_its_unit_cost(): void
    {
        $this->receive(100, 10);

        $this->assertDatabaseHas('stock_inventory', [
            'product_id' => $this->product->id,
            'quantity_on_hand' => 100,
            'average_cost' => 10,
        ]);
    }

    public function test_second_reception_recalculates_weighted_average_cost(): void
    {
        $this->receive(100, 10); // 100 @ 10 -> avg 10
        $this->receive(50, 16);  // (100*10 + 50*16) / 150 = 12

        $this->assertDatabaseHas('stock_inventory', [
            'product_id' => $this->product->id,
            'quantity_on_hand' => 150,
            'average_cost' => 12,
        ]);
    }

    public function test_sortie_movement_cost_uses_cump_not_catalog_unit_cost(): void
    {
        $this->receive(100, 10); // average_cost becomes 10, catalog unit_cost stays 20

        $this->sortie(10)->assertRedirect();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => 'out',
            'quantity' => 10,
            'unit_cost' => 10, // CUMP, not the 20 catalog price
            'total_cost' => 100,
        ]);
    }

    // --- Negative-stock guard ---

    public function test_sortie_exceeding_available_stock_is_rejected(): void
    {
        $this->receive(50, 10);

        $this->sortie(100)->assertSessionHasErrors('quantity');

        $this->assertDatabaseHas('stock_inventory', ['product_id' => $this->product->id, 'quantity_on_hand' => 50]);
        $this->assertDatabaseCount('manual_stock_entries', 0);
    }

    public function test_sortie_exactly_equal_to_available_stock_is_allowed(): void
    {
        $this->receive(50, 10);

        $this->sortie(50)->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('stock_inventory', ['product_id' => $this->product->id, 'quantity_on_hand' => 0]);
    }

    public function test_sortie_on_a_product_with_no_reception_yet_is_rejected(): void
    {
        // No réception at all — inventory row doesn't exist yet, quantity_on_hand is effectively 0.
        $this->sortie(1)->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('manual_stock_entries', 0);
    }

    // --- Automatic low-stock alert sync ---

    public function test_stock_dropping_to_or_below_minimum_creates_a_low_stock_alert(): void
    {
        $this->receive(150, 10); // min_stock_level is 100

        $this->sortie(60); // 150 - 60 = 90, at/below the 100 minimum

        $this->assertDatabaseHas('stock_alerts', [
            'product_id' => $this->product->id,
            'alert_type' => 'low_stock',
            'is_resolved' => false,
            'current_value' => 90,
        ]);
    }

    public function test_stock_staying_above_minimum_does_not_create_an_alert(): void
    {
        $this->receive(150, 10);

        $this->sortie(20); // 150 - 20 = 130, still above the 100 minimum

        $this->assertDatabaseCount('stock_alerts', 0);
    }

    public function test_repeated_low_stock_syncs_update_the_existing_alert_instead_of_duplicating(): void
    {
        $this->receive(150, 10);
        $this->sortie(60); // 90 left, triggers an alert
        $this->sortie(10); // 80 left, still low — should update, not duplicate

        $this->assertDatabaseCount('stock_alerts', 1);
        $this->assertDatabaseHas('stock_alerts', [
            'product_id' => $this->product->id,
            'current_value' => 80,
            'is_resolved' => false,
        ]);
    }

    public function test_stock_recovering_above_minimum_resolves_the_alert(): void
    {
        $this->receive(150, 10);
        $this->sortie(60); // 90 left, below minimum — alert opens

        $this->assertDatabaseHas('stock_alerts', ['product_id' => $this->product->id, 'is_resolved' => false]);

        $this->receive(50, 10); // 90 + 50 = 140, back above the 100 minimum

        $this->assertDatabaseHas('stock_alerts', ['product_id' => $this->product->id, 'is_resolved' => true]);
    }

    // --- Bloc/Secteur/Parcelle hierarchy validation ---

    public function test_sortie_rejects_a_sector_that_does_not_belong_to_the_chosen_bloc(): void
    {
        $this->receive(100, 10);

        $blocA = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        $blocB = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B2']);
        $sectorOfB = Sector::create(['bloc_id' => $blocB->id, 'name' => 'S1']);

        $this->sortie(10, ['bloc_id' => $blocA->id, 'sector_id' => $sectorOfB->id])
            ->assertSessionHasErrors('sector_id');

        $this->assertDatabaseCount('manual_stock_entries', 0);
    }

    public function test_sortie_rejects_a_parcelle_that_does_not_belong_to_the_chosen_sector(): void
    {
        $this->receive(100, 10);

        $bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        $sectorA = Sector::create(['bloc_id' => $bloc->id, 'name' => 'S1']);
        $sectorB = Sector::create(['bloc_id' => $bloc->id, 'name' => 'S2']);
        $parcelleOfB = Parcelle::create(['bloc_id' => $bloc->id, 'sector_id' => $sectorB->id, 'name' => 'P1']);

        $this->sortie(10, [
            'bloc_id' => $bloc->id,
            'sector_id' => $sectorA->id,
            'parcelle_id' => $parcelleOfB->id,
        ])->assertSessionHasErrors('parcelle_id');

        $this->assertDatabaseCount('manual_stock_entries', 0);
    }

    public function test_sortie_accepts_a_consistent_bloc_sector_parcelle_chain(): void
    {
        $this->receive(100, 10);

        $bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        $sector = Sector::create(['bloc_id' => $bloc->id, 'name' => 'S1']);
        $parcelle = Parcelle::create(['bloc_id' => $bloc->id, 'sector_id' => $sector->id, 'name' => 'P1']);

        $this->sortie(10, [
            'bloc_id' => $bloc->id,
            'sector_id' => $sector->id,
            'parcelle_id' => $parcelle->id,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('manual_stock_entries', [
            'bloc_id' => $bloc->id,
            'sector_id' => $sector->id,
            'parcelle_id' => $parcelle->id,
        ]);
    }

    public function test_sortie_accepts_a_bloc_with_no_sector_or_parcelle_chosen(): void
    {
        $this->receive(100, 10);

        $bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);

        $this->sortie(10, ['bloc_id' => $bloc->id])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('manual_stock_entries', ['bloc_id' => $bloc->id, 'sector_id' => null]);
    }
}
