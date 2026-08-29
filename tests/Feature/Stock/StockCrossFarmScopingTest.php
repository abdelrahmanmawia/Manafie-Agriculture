<?php

namespace Tests\Feature\Stock;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\Product;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the pre-deployment audit finding: every Stock controller's
 * show/update/destroy/verify method trusted the route-bound model with no farm-ownership
 * check at all, letting any authenticated user view or mutate another farm's stock records
 * by walking IDs. index()/store() were already properly scoped via scopedFarmId()/
 * resolveWriteFarmId() — this suite locks in the same scoping now applied everywhere else.
 */
class StockCrossFarmScopingTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farmA;
    private Farm $farmB;
    private User $managerA;
    private Product $productA;
    private Product $productB;
    private Vehicle $vehicleB;
    private ManualStockEntry $entryB;
    private StockInventory $inventoryB;
    private FuelTransaction $fuelTransactionB;
    private Employee $employeeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farmA = Farm::create(['name' => 'Farm A']);
        $this->farmB = Farm::create(['name' => 'Farm B']);

        $this->managerA = User::factory()->create([
            'role' => 'farm_manager',
            'farm_id' => $this->farmA->id,
        ]);

        $this->productA = Product::create([
            'farm_id' => $this->farmA->id, 'name' => 'Product A', 'category' => 'fertilizers',
            'unit_type' => 'kg', 'min_stock_level' => 10, 'unit_cost' => 5, 'is_active' => true,
        ]);

        $this->productB = Product::create([
            'farm_id' => $this->farmB->id, 'name' => 'Product B', 'category' => 'fertilizers',
            'unit_type' => 'kg', 'min_stock_level' => 10, 'unit_cost' => 5, 'is_active' => true,
        ]);

        $this->inventoryB = StockInventory::create([
            'product_id' => $this->productB->id, 'quantity_on_hand' => 500, 'quantity_reserved' => 0,
        ]);

        $this->vehicleB = Vehicle::create([
            'farm_id' => $this->farmB->id, 'name' => 'Vehicle B', 'plate_number' => 'B-00001',
            'type' => 'tractor', 'fuel_type' => 'diesel', 'is_active' => true,
        ]);

        $enterpriseB = Enterprise::create([
            'farm_id' => $this->farmB->id, 'name' => 'Enterprise B',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 90,
        ]);

        $this->employeeB = Employee::create([
            'farm_id' => $this->farmB->id, 'enterprise_id' => $enterpriseB->id,
            'matricule' => 'B-1', 'full_name' => 'Employee B', 'type' => 'persea',
            'base_rate' => 90, 'is_active' => true, 'badge_uuid' => 'badge-b-1',
        ]);

        $this->entryB = ManualStockEntry::create([
            'farm_id' => $this->farmB->id, 'product_id' => $this->productB->id,
            'entry_type' => 'consumption', 'quantity' => 5, 'date' => '2026-01-01',
            'entered_by' => $this->managerA->id, 'is_verified' => false,
        ]);
        StockMovement::create([
            'product_id' => $this->productB->id, 'movement_type' => 'out', 'quantity' => 5,
            'unit_cost' => 5, 'total_cost' => 25, 'reference_type' => 'manual_entry',
            'reference_id' => $this->entryB->id, 'performed_by' => $this->managerA->id, 'date' => '2026-01-01',
        ]);

        $this->fuelTransactionB = FuelTransaction::create([
            'farm_id' => $this->farmB->id, 'vehicle_id' => $this->vehicleB->id, 'product_id' => $this->productB->id,
            'transaction_type' => 'fill_up', 'quantity_liters' => 40, 'unit_price_per_liter' => 12,
            'total_cost' => 480, 'date' => '2026-01-01', 'performed_by' => $this->managerA->id,
        ]);
    }

    public function test_farm_manager_cannot_view_another_farms_product(): void
    {
        $this->actingAs($this->managerA)
            ->get('/stock/products/' . $this->productB->id)
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_update_another_farms_product(): void
    {
        $this->actingAs($this->managerA)
            ->put('/stock/products/' . $this->productB->id, [
                'name' => 'Hijacked', 'category' => 'fertilizers', 'unit_type' => 'kg',
            ])
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_toggle_another_farms_product(): void
    {
        $this->actingAs($this->managerA)
            ->post('/stock/products/' . $this->productB->id . '/toggle-active')
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_view_another_farms_vehicle(): void
    {
        $this->actingAs($this->managerA)
            ->get('/stock/vehicles/' . $this->vehicleB->id)
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_update_another_farms_vehicle(): void
    {
        $this->actingAs($this->managerA)
            ->put('/stock/vehicles/' . $this->vehicleB->id, ['name' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_create_maintenance_log_on_another_farms_vehicle(): void
    {
        $this->actingAs($this->managerA)
            ->post('/stock/vehicles/' . $this->vehicleB->id . '/maintenance-logs', [
                'description' => 'Hijacked repair',
                'performed_at' => now()->toDateString(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('vehicle_maintenance_logs', 0);
    }

    public function test_farm_manager_cannot_view_another_farms_manual_stock_entry(): void
    {
        $this->actingAs($this->managerA)
            ->get('/stock/manual-entries/' . $this->entryB->id)
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_update_another_farms_manual_stock_entry(): void
    {
        $this->actingAs($this->managerA)
            ->put('/stock/manual-entries/' . $this->entryB->id, ['quantity' => 999])
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_use_another_farms_product_in_own_manual_stock_entry(): void
    {
        $this->actingAs($this->managerA)
            ->post('/stock/manual-entries', [
                'product_id' => $this->productB->id, // foreign product
                'entry_type' => 'consumption', 'quantity' => 1, 'date' => '2026-01-06',
            ])
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_receive_stock_for_another_farms_product(): void
    {
        $this->actingAs($this->managerA)
            ->post('/stock/movements/in', [
                'product_id' => $this->productB->id, 'quantity' => 10, 'date' => '2026-01-06',
            ])
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_view_another_farms_stock_inventory(): void
    {
        $this->actingAs($this->managerA)
            ->get('/stock/inventory/' . $this->inventoryB->id)
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_count_another_farms_stock_inventory(): void
    {
        $this->actingAs($this->managerA)
            ->post('/stock/inventory/count', [
                'product_id' => $this->productB->id, 'counted_quantity' => 999,
            ])
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_view_another_farms_fuel_transaction(): void
    {
        $this->actingAs($this->managerA)
            ->get('/stock/fuel-transactions/' . $this->fuelTransactionB->id)
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_print_another_farms_employee_badge(): void
    {
        $response = $this->actingAs($this->managerA)
            ->get('/badges/print?employee_ids=' . $this->employeeB->id);

        // Not forbidden outright (an empty-but-valid selection still renders a PDF) — the
        // real assertion is that Farm B's employee data never made it into that PDF.
        $response->assertOk();
        $this->assertStringNotContainsString('Employee B', $response->getContent());
    }
}
