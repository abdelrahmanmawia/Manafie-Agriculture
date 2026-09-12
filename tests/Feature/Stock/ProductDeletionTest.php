<?php

namespace Tests\Feature\Stock;

use App\Models\Farm;
use App\Models\Product;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product uses SoftDeletes and nothing in the app calls forceDelete() on one — the normal
 * destroy() route just archives it, and every related model's product() relation uses
 * withTrashed() so historical data keeps resolving correctly (see StockInventory.php's own
 * comment). This covers the DB-level safety net underneath that: product_id on
 * stock_inventory/stock_movements/stock_alerts/manual_stock_entries was changed from
 * onDelete('cascade') to onDelete('restrict'), so a real hard delete (however it happens — a
 * future code path, a manual tinker mistake) fails loudly instead of silently wiping stock
 * history that this app treats as a permanent audit record.
 */
class ProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_destroy_only_archives_the_product_and_keeps_its_history(): void
    {
        $farm = Farm::create(['name' => 'Farm A']);
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);
        $product = Product::create([
            'farm_id' => $farm->id, 'name' => 'Engrais Test', 'unit_type' => 'kg',
            'min_stock_level' => 10, 'unit_cost' => 5, 'is_active' => true,
        ]);
        StockInventory::create(['product_id' => $product->id, 'quantity_on_hand' => 50, 'quantity_reserved' => 0]);
        StockMovement::create([
            'product_id' => $product->id, 'movement_type' => 'in', 'quantity' => 50,
            'unit_cost' => 5, 'total_cost' => 250, 'date' => now(),
        ]);

        $this->actingAs($manager)
            ->delete(route('stock.products.destroy', $product))
            ->assertRedirect();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        // The product is gone from the active list, but its history is untouched.
        $this->assertDatabaseHas('stock_inventory', ['product_id' => $product->id, 'quantity_on_hand' => 50]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id]);
    }

    public function test_force_deleting_a_product_with_stock_history_is_blocked_at_the_database_level(): void
    {
        $farm = Farm::create(['name' => 'Farm A']);
        $product = Product::create([
            'farm_id' => $farm->id, 'name' => 'Engrais Test', 'unit_type' => 'kg',
            'min_stock_level' => 10, 'unit_cost' => 5, 'is_active' => true,
        ]);
        StockMovement::create([
            'product_id' => $product->id, 'movement_type' => 'in', 'quantity' => 50,
            'unit_cost' => 5, 'total_cost' => 250, 'date' => now(),
        ]);

        $this->expectException(QueryException::class);

        $product->forceDelete();
    }

    public function test_force_deleting_a_product_with_no_stock_history_succeeds(): void
    {
        $farm = Farm::create(['name' => 'Farm A']);
        $product = Product::create([
            'farm_id' => $farm->id, 'name' => 'Engrais Test', 'unit_type' => 'kg',
            'min_stock_level' => 10, 'unit_cost' => 5, 'is_active' => true,
        ]);

        $product->forceDelete();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }
}
