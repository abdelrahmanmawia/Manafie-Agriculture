<?php

namespace Tests\Feature\Stock;

use App\Models\Farm;
use App\Models\ManualStockEntry;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A data_entry user is meant to log réceptions/sorties and edit existing records, but not
 * create/delete/deactivate catalog items or resolve alerts — see CLAUDE.md's authorization
 * model. These checks previously existed only in the frontend (hidden buttons); this suite
 * locks in the server-side abort(403) guards added to each Stock controller.
 */
class StockDataEntryRoleTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;
    private User $dataEntry;
    private User $farmManager;
    private Product $product;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Test Farm']);

        $this->dataEntry = User::factory()->create([
            'role' => 'data_entry',
            'farm_id' => $this->farm->id,
        ]);

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
            'unit_cost' => 10,
            'is_active' => true,
        ]);

        StockInventory::create([
            'product_id' => $this->product->id,
            'quantity_on_hand' => 500,
            'quantity_reserved' => 0,
        ]);

        $this->vehicle = Vehicle::create([
            'farm_id' => $this->farm->id,
            'name' => 'Tracteur Test',
            'plate_number' => 'T-00001',
            'type' => 'tractor',
            'fuel_type' => 'diesel',
            'is_active' => true,
        ]);
    }

    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nouveau Produit',
            'category' => 'seeds',
            'unit_type' => 'units',
            'min_stock_level' => 10,
        ], $overrides);
    }

    private function vehiclePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nouveau Véhicule',
            'plate_number' => 'N-00001',
            'type' => 'car',
            'fuel_type' => 'gasoline',
        ], $overrides);
    }

    // --- ProductController ---

    public function test_data_entry_cannot_create_product(): void
    {
        $this->actingAs($this->dataEntry)
            ->post(route('stock.products.store'), $this->productPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('products', 1);
    }

    public function test_farm_manager_can_create_product(): void
    {
        $this->actingAs($this->farmManager)
            ->post(route('stock.products.store'), $this->productPayload())
            ->assertRedirect();

        $this->assertDatabaseCount('products', 2);
    }

    public function test_data_entry_cannot_delete_product(): void
    {
        $this->actingAs($this->dataEntry)
            ->delete(route('stock.products.destroy', $this->product))
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'deleted_at' => null]);
    }

    public function test_data_entry_cannot_toggle_product_active(): void
    {
        $this->actingAs($this->dataEntry)
            ->post(route('stock.products.toggle-active', $this->product))
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'is_active' => true]);
    }

    public function test_data_entry_can_update_product(): void
    {
        $this->actingAs($this->dataEntry)
            ->put(route('stock.products.update', $this->product), [
                'name' => $this->product->name,
                'category' => $this->product->category,
                'unit_type' => $this->product->unit_type,
                'min_stock_level' => 250,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'min_stock_level' => 250]);
    }

    /**
     * Regression: PHP only parses multipart/form-data into $_POST/$_FILES for a real POST,
     * never PUT — attaching a photo forces the frontend into multipart/form-data, so it must
     * route through POST + _method=put (Employees.jsx / Stock/Index.jsx / Stock/Show.jsx all
     * do this now). Also covers the is_active boolean coercion: FormData serializes a JS
     * `true` as the literal string "true", which Laravel's strict `boolean` rule used to
     * reject outright.
     */
    public function test_updating_product_with_a_photo_via_post_method_spoofing_succeeds(): void
    {
        $photo = \Illuminate\Http\UploadedFile::fake()->image('product.jpg');

        $this->actingAs($this->farmManager)
            ->post(route('stock.products.update', $this->product), [
                '_method' => 'put',
                'name' => 'Updated Via Multipart',
                'category' => $this->product->category,
                'unit_type' => $this->product->unit_type,
                'is_active' => 'true', // exactly what FormData serializes a JS boolean to
                'image' => $photo,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'name' => 'Updated Via Multipart',
            'is_active' => true,
        ]);
        $this->assertNotNull($this->product->fresh()->image);
    }

    // --- VehicleController ---

    public function test_data_entry_cannot_create_vehicle(): void
    {
        $this->actingAs($this->dataEntry)
            ->post(route('stock.vehicles.store'), $this->vehiclePayload())
            ->assertForbidden();

        $this->assertDatabaseCount('vehicles', 1);
    }

    public function test_farm_manager_can_create_vehicle(): void
    {
        $this->actingAs($this->farmManager)
            ->post(route('stock.vehicles.store'), $this->vehiclePayload())
            ->assertRedirect();

        $this->assertDatabaseCount('vehicles', 2);
    }

    public function test_data_entry_cannot_delete_vehicle(): void
    {
        $this->actingAs($this->dataEntry)
            ->delete(route('stock.vehicles.destroy', $this->vehicle))
            ->assertForbidden();

        $this->assertDatabaseHas('vehicles', ['id' => $this->vehicle->id]);
    }

    public function test_data_entry_cannot_toggle_vehicle_active(): void
    {
        $this->actingAs($this->dataEntry)
            ->post(route('stock.vehicles.toggle-active', $this->vehicle))
            ->assertForbidden();

        $this->assertDatabaseHas('vehicles', ['id' => $this->vehicle->id, 'is_active' => true]);
    }

    public function test_data_entry_can_update_vehicle(): void
    {
        $this->actingAs($this->dataEntry)
            ->put(route('stock.vehicles.update', $this->vehicle), [
                'name' => 'Tracteur Renommé',
                'plate_number' => $this->vehicle->plate_number,
                'type' => $this->vehicle->type,
                'fuel_type' => $this->vehicle->fuel_type,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('vehicles', ['id' => $this->vehicle->id, 'name' => 'Tracteur Renommé']);
    }

    // --- ManualStockEntryController ---

    public function test_data_entry_cannot_create_manual_stock_entry(): void
    {
        $this->actingAs($this->dataEntry)
            ->post(route('stock.manual-entries.store'), [
                'product_id' => $this->product->id,
                'entry_type' => 'consumption',
                'quantity' => 5,
                'date' => now()->toDateString(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('manual_stock_entries', 0);
    }

    public function test_farm_manager_can_create_manual_stock_entry(): void
    {
        $this->actingAs($this->farmManager)
            ->post(route('stock.manual-entries.store'), [
                'product_id' => $this->product->id,
                'entry_type' => 'consumption',
                'quantity' => 5,
                'date' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('manual_stock_entries', 1);
    }

    public function test_data_entry_cannot_delete_manual_stock_entry(): void
    {
        $entry = ManualStockEntry::create([
            'farm_id' => $this->farm->id,
            'product_id' => $this->product->id,
            'entry_type' => 'consumption',
            'quantity' => 5,
            'date' => now()->toDateString(),
            'entered_by' => $this->farmManager->id,
        ]);

        $this->actingAs($this->dataEntry)
            ->delete(route('stock.manual-entries.destroy', $entry))
            ->assertForbidden();

        $this->assertDatabaseHas('manual_stock_entries', ['id' => $entry->id]);
    }

    public function test_data_entry_cannot_verify_manual_stock_entry(): void
    {
        $entry = ManualStockEntry::create([
            'farm_id' => $this->farm->id,
            'product_id' => $this->product->id,
            'entry_type' => 'consumption',
            'quantity' => 5,
            'date' => now()->toDateString(),
            'entered_by' => $this->farmManager->id,
        ]);

        $this->actingAs($this->dataEntry)
            ->post(route('stock.manual-entries.verify', $entry))
            ->assertForbidden();

        $this->assertDatabaseHas('manual_stock_entries', ['id' => $entry->id, 'is_verified' => false]);
    }

    public function test_data_entry_can_update_manual_stock_entry(): void
    {
        $entry = ManualStockEntry::create([
            'farm_id' => $this->farm->id,
            'product_id' => $this->product->id,
            'entry_type' => 'consumption',
            'quantity' => 5,
            'date' => now()->toDateString(),
            'entered_by' => $this->farmManager->id,
        ]);

        $this->actingAs($this->dataEntry)
            ->put(route('stock.manual-entries.update', $entry), [
                'notes' => 'Updated by data entry',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('manual_stock_entries', ['id' => $entry->id, 'notes' => 'Updated by data entry']);
    }

    // --- StockAlertController ---

    public function test_data_entry_cannot_resolve_stock_alert(): void
    {
        $alert = StockAlert::create([
            'product_id' => $this->product->id,
            'alert_type' => 'low_stock',
            'threshold_value' => 100,
            'current_value' => 50,
            'is_resolved' => false,
        ]);

        $this->actingAs($this->dataEntry)
            ->post(route('stock.alerts.resolve', $alert))
            ->assertForbidden();

        $this->assertDatabaseHas('stock_alerts', ['id' => $alert->id, 'is_resolved' => false]);
    }

    public function test_farm_manager_can_resolve_stock_alert(): void
    {
        $alert = StockAlert::create([
            'product_id' => $this->product->id,
            'alert_type' => 'low_stock',
            'threshold_value' => 100,
            'current_value' => 50,
            'is_resolved' => false,
        ]);

        $this->actingAs($this->farmManager)
            ->post(route('stock.alerts.resolve', $alert))
            ->assertRedirect();

        $this->assertDatabaseHas('stock_alerts', ['id' => $alert->id, 'is_resolved' => true]);
    }

    // --- StockInventoryController ---

    public function test_data_entry_cannot_adjust_stock_inventory(): void
    {
        $this->actingAs($this->dataEntry)
            ->post(route('stock.inventory.adjust'), [
                'product_id' => $this->product->id,
                'quantity' => 999,
                'reason' => 'Test adjustment',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('stock_inventory', ['product_id' => $this->product->id, 'quantity_on_hand' => 500]);
    }

    public function test_data_entry_cannot_count_stock_inventory(): void
    {
        $this->actingAs($this->dataEntry)
            ->post(route('stock.inventory.count'), [
                'product_id' => $this->product->id,
                'counted_quantity' => 999,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('stock_inventory', ['product_id' => $this->product->id, 'quantity_on_hand' => 500]);
    }

    // --- StockMovementController — réceptions stay allowed for data_entry ---

    public function test_data_entry_can_receive_stock(): void
    {
        $this->actingAs($this->dataEntry)
            ->post(route('stock.movements.in'), [
                'product_id' => $this->product->id,
                'quantity' => 50,
                'date' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('stock_inventory', ['product_id' => $this->product->id, 'quantity_on_hand' => 550]);
        $this->assertDatabaseCount('stock_movements', 1);
    }
}
