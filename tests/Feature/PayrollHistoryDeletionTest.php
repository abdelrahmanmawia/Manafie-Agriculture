<?php

namespace Tests\Feature;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Product;
use App\Models\Quinzaine;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting an employee, operation, bloc or division used to cascade straight through
 * pointage_records (closed quinzaines included) and harvests, and deleting a user cascaded
 * through the maintenance logs they entered. Migration 000042 made those FKs restrict/null-out;
 * these lock in that the history survives and the user gets a message instead of a 500.
 */
class PayrollHistoryDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $enterprise;

    private User $manager;

    private Employee $employee;

    private Operation $operation;

    private Bloc $bloc;

    private Quinzaine $closedQuinzaine;

    private PointageRecord $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->enterprise = Enterprise::create([
            'farm_id' => $this->farm->id, 'name' => 'Division', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->employee = Employee::create([
            'farm_id' => $this->farm->id, 'enterprise_id' => $this->enterprise->id, 'matricule' => '001',
            'full_name' => 'Worker', 'base_rate' => 97.44, 'is_active' => true,
        ]);
        $this->operation = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Taille']);
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        $this->closedQuinzaine = Quinzaine::create([
            'enterprise_id' => $this->enterprise->id, 'label' => '1QZ', 'start_date' => '2026-01-01',
            'end_date' => '2026-01-15', 'is_closed' => true,
        ]);
        $this->record = PointageRecord::create([
            'employee_id' => $this->employee->id, 'quinzaine_id' => $this->closedQuinzaine->id,
            'operation_id' => $this->operation->id, 'bloc_id' => $this->bloc->id, 'date' => '2026-01-05',
            'hours' => 0, 'is_jf' => false, 'rate' => 97.44, 'brut' => 97.44, 'net' => 90.87,
        ]);
    }

    public function test_employee_with_pointage_is_not_deleted_and_keeps_history(): void
    {
        $this->actingAs($this->manager)
            ->delete('/employees/'.$this->employee->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('employees', ['id' => $this->employee->id]);
        $this->assertDatabaseHas('pointage_records', ['id' => $this->record->id]);
    }

    public function test_employee_without_pointage_can_still_be_deleted(): void
    {
        $unused = Employee::create([
            'farm_id' => $this->farm->id, 'enterprise_id' => $this->enterprise->id, 'matricule' => '002',
            'full_name' => 'Typo', 'base_rate' => 97.44, 'is_active' => true,
        ]);

        $this->actingAs($this->manager)
            ->delete('/employees/'.$unused->id)
            ->assertRedirect(route('employees.index'));

        $this->assertDatabaseMissing('employees', ['id' => $unused->id]);
    }

    public function test_operation_used_by_pointage_is_not_deleted(): void
    {
        $this->actingAs($this->manager)
            ->delete('/farms/operations/'.$this->operation->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('operations', ['id' => $this->operation->id]);
        $this->assertDatabaseHas('pointage_records', ['id' => $this->record->id]);
    }

    public function test_bloc_used_by_pointage_is_not_deleted(): void
    {
        $this->actingAs($this->manager)
            ->delete('/farms/blocs/'.$this->bloc->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('blocs', ['id' => $this->bloc->id]);
        $this->assertDatabaseHas('pointage_records', ['id' => $this->record->id]);
    }

    public function test_bloc_with_harvests_is_not_deleted(): void
    {
        $otherBloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B2']);
        $harvest = Harvest::create([
            'farm_id' => $this->farm->id, 'bloc_id' => $otherBloc->id, 'date' => '2026-01-05',
            'variety' => 'Hass', 'quantity_kg' => 100,
        ]);

        $this->actingAs($this->manager)
            ->delete('/farms/blocs/'.$otherBloc->id)
            ->assertSessionHas('error');

        $this->assertDatabaseHas('harvests', ['id' => $harvest->id]);
    }

    public function test_division_with_pointage_history_is_not_deleted_and_keeps_its_employees(): void
    {
        $this->actingAs($this->manager)
            ->delete('/enterprises/'.$this->enterprise->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('enterprises', ['id' => $this->enterprise->id]);
        $this->assertDatabaseHas('pointage_records', ['id' => $this->record->id]);
        $this->assertDatabaseHas('employees', ['id' => $this->employee->id, 'enterprise_id' => $this->enterprise->id]);
    }

    public function test_unused_division_can_still_be_deleted(): void
    {
        $unused = Enterprise::create([
            'farm_id' => $this->farm->id, 'name' => 'Empty', 'contract_type' => 'sans_contrat', 'default_brut_rate' => 90,
        ]);

        $this->actingAs($this->manager)
            ->delete('/enterprises/'.$unused->id)
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('enterprises', ['id' => $unused->id]);
    }

    public function test_deleting_a_user_keeps_the_maintenance_logs_they_entered(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'farm_id' => null]);
        $vehicle = Vehicle::create([
            'farm_id' => $this->farm->id, 'name' => 'Tracteur', 'type' => 'tractor', 'is_active' => true,
        ]);
        $log = VehicleMaintenanceLog::create([
            'vehicle_id' => $vehicle->id, 'farm_id' => $this->farm->id, 'description' => 'Vidange',
            'performed_at' => '2026-01-05', 'created_by' => $this->manager->id,
        ]);

        $this->actingAs($superAdmin)
            ->delete('/users/'.$this->manager->id)
            ->assertSessionHas('success');

        $this->assertDatabaseHas('vehicle_maintenance_logs', ['id' => $log->id, 'created_by' => null]);
    }

    public function test_deleting_a_user_with_stock_history_is_refused_with_a_message(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'farm_id' => null]);
        $product = Product::create([
            'farm_id' => $this->farm->id, 'name' => 'Engrais', 'category' => 'fertilizers',
            'unit_type' => 'kg', 'min_stock_level' => 0, 'unit_cost' => 10, 'is_active' => true,
        ]);
        StockMovement::create([
            'product_id' => $product->id, 'movement_type' => 'in', 'quantity' => 5,
            'performed_by' => $this->manager->id, 'date' => '2026-01-05',
        ]);

        $this->actingAs($superAdmin)
            ->delete('/users/'.$this->manager->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->manager->id]);
    }
}
