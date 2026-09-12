<?php

namespace Tests\Feature\Stock;

use App\Models\Farm;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Supplier master list (a simple named list picked when logging an Entrée — no
 * order/procurement workflow, deliberately) and the supplier_id/numero_bl fields it feeds into
 * StockMovementController::stockIn().
 */
class SupplierTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farmA;
    private Farm $farmB;
    private User $managerA;
    private Product $productA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farmA = Farm::create(['name' => 'Farm A']);
        $this->farmB = Farm::create(['name' => 'Farm B']);
        $this->managerA = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farmA->id]);

        $this->productA = Product::create([
            'farm_id' => $this->farmA->id, 'name' => 'Engrais Test', 'unit_type' => 'kg',
            'min_stock_level' => 10, 'unit_cost' => 20, 'is_active' => true,
        ]);
    }

    public function test_index_lists_only_this_farms_suppliers(): void
    {
        Supplier::create(['farm_id' => $this->farmA->id, 'name' => 'GDIRAGRI sarl']);
        Supplier::create(['farm_id' => $this->farmB->id, 'name' => 'Other Farm Supplier']);

        $response = $this->actingAs($this->managerA)->get(route('stock.suppliers.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Stock/Suppliers/Index')
            ->has('suppliers', 1)
            ->where('suppliers.0.name', 'GDIRAGRI sarl')
        );
    }

    public function test_show_returns_stats_and_delivery_history_for_this_supplier_only(): void
    {
        $supplier = Supplier::create(['farm_id' => $this->farmA->id, 'name' => 'GDIRAGRI sarl']);
        $otherSupplier = Supplier::create(['farm_id' => $this->farmA->id, 'name' => 'Other Supplier']);
        $otherProduct = Product::create([
            'farm_id' => $this->farmA->id, 'name' => 'Semences Test', 'unit_type' => 'kg',
            'min_stock_level' => 10, 'unit_cost' => 5, 'is_active' => true,
        ]);

        StockMovement::create([
            'product_id' => $this->productA->id, 'supplier_id' => $supplier->id, 'numero_bl' => 'BL-1',
            'movement_type' => 'in', 'quantity' => 100, 'unit_cost' => 20, 'total_cost' => 2000, 'date' => '2026-01-01',
        ]);
        StockMovement::create([
            'product_id' => $otherProduct->id, 'supplier_id' => $supplier->id, 'numero_bl' => 'BL-2',
            'movement_type' => 'in', 'quantity' => 50, 'unit_cost' => 5, 'total_cost' => 250, 'date' => '2026-02-01',
        ]);
        // Belongs to a different supplier — must not leak into $supplier's stats/history.
        StockMovement::create([
            'product_id' => $this->productA->id, 'supplier_id' => $otherSupplier->id,
            'movement_type' => 'in', 'quantity' => 999, 'unit_cost' => 1, 'total_cost' => 999, 'date' => '2026-03-01',
        ]);

        $response = $this->actingAs($this->managerA)->get(route('stock.suppliers.show', $supplier));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Stock/Suppliers/Show')
            ->where('stats.total_deliveries', 2)
            ->where('stats.total_value', 2250)
            ->where('stats.distinct_products', 2)
            ->has('supplier.stock_movements', 2)
        );
    }

    public function test_farm_manager_cannot_view_another_farms_supplier(): void
    {
        $supplier = Supplier::create(['farm_id' => $this->farmB->id, 'name' => 'Other Farm Supplier']);

        $this->actingAs($this->managerA)
            ->get(route('stock.suppliers.show', $supplier))
            ->assertForbidden();
    }

    public function test_farm_manager_can_create_a_supplier(): void
    {
        $this->actingAs($this->managerA)
            ->post(route('stock.suppliers.store'), ['name' => 'GDIRAGRI sarl'])
            ->assertRedirect();

        $this->assertDatabaseHas('suppliers', ['farm_id' => $this->farmA->id, 'name' => 'GDIRAGRI sarl', 'is_active' => true]);
    }

    public function test_supplier_name_must_be_unique_within_the_same_farm(): void
    {
        Supplier::create(['farm_id' => $this->farmA->id, 'name' => 'GDIRAGRI sarl']);

        $this->actingAs($this->managerA)
            ->post(route('stock.suppliers.store'), ['name' => 'GDIRAGRI sarl'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_same_supplier_name_is_allowed_across_different_farms(): void
    {
        Supplier::create(['farm_id' => $this->farmB->id, 'name' => 'GDIRAGRI sarl']);

        $this->actingAs($this->managerA)
            ->post(route('stock.suppliers.store'), ['name' => 'GDIRAGRI sarl'])
            ->assertRedirect();

        $this->assertDatabaseCount('suppliers', 2);
    }

    public function test_farm_manager_can_deactivate_a_supplier(): void
    {
        $supplier = Supplier::create(['farm_id' => $this->farmA->id, 'name' => 'GDIRAGRI sarl']);

        $this->actingAs($this->managerA)
            ->put(route('stock.suppliers.update', $supplier), ['is_active' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'is_active' => false]);
    }

    public function test_farm_manager_cannot_update_another_farms_supplier(): void
    {
        $supplier = Supplier::create(['farm_id' => $this->farmB->id, 'name' => 'GDIRAGRI sarl']);

        $this->actingAs($this->managerA)
            ->put(route('stock.suppliers.update', $supplier), ['name' => 'Renamed'])
            ->assertForbidden();
    }

    public function test_deleting_a_supplier_not_used_by_any_movement_succeeds(): void
    {
        $supplier = Supplier::create(['farm_id' => $this->farmA->id, 'name' => 'GDIRAGRI sarl']);

        $this->actingAs($this->managerA)
            ->delete(route('stock.suppliers.destroy', $supplier))
            ->assertRedirect();

        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_deleting_a_supplier_used_by_a_movement_is_blocked(): void
    {
        $supplier = Supplier::create(['farm_id' => $this->farmA->id, 'name' => 'GDIRAGRI sarl']);
        StockMovement::create([
            'product_id' => $this->productA->id, 'supplier_id' => $supplier->id, 'movement_type' => 'in',
            'quantity' => 100, 'unit_cost' => 20, 'total_cost' => 2000, 'date' => now(),
        ]);

        $this->actingAs($this->managerA)
            ->delete(route('stock.suppliers.destroy', $supplier))
            ->assertSessionHasErrors('supplier');

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    public function test_stock_in_saves_supplier_and_numero_bl_on_the_movement(): void
    {
        $supplier = Supplier::create(['farm_id' => $this->farmA->id, 'name' => 'GDIRAGRI sarl']);

        $this->actingAs($this->managerA)
            ->post(route('stock.movements.in'), [
                'product_id' => $this->productA->id,
                'quantity' => 100,
                'unit_cost' => 20,
                'supplier_id' => $supplier->id,
                'numero_bl' => '58788711',
                'date' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->productA->id,
            'supplier_id' => $supplier->id,
            'numero_bl' => '58788711',
        ]);
    }

    public function test_stock_in_rejects_a_supplier_from_another_farm(): void
    {
        $supplier = Supplier::create(['farm_id' => $this->farmB->id, 'name' => 'GDIRAGRI sarl']);

        $this->actingAs($this->managerA)
            ->post(route('stock.movements.in'), [
                'product_id' => $this->productA->id,
                'quantity' => 100,
                'supplier_id' => $supplier->id,
                'date' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('supplier_id');
    }

    public function test_stock_in_without_a_supplier_still_succeeds(): void
    {
        $this->actingAs($this->managerA)
            ->post(route('stock.movements.in'), [
                'product_id' => $this->productA->id,
                'quantity' => 100,
                'date' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->productA->id,
            'supplier_id' => null,
        ]);
    }
}
