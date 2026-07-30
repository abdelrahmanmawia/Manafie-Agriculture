<?php

namespace Tests\Feature;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the pre-deployment audit findings: cross-farm IDOR (a farm_manager
 * must never read/mutate another farm's data by passing a foreign ID), and missing role
 * checks (data_entry must never be able to create/delete farms, users, divisions, or close
 * a pay period, even though the UI hides those buttons from them).
 */
class SecurityScopingTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farmA;
    private Farm $farmB;
    private Enterprise $enterpriseA;
    private Enterprise $enterpriseB;
    private User $managerA;
    private User $dataEntryA;
    private Employee $employeeA;
    private Employee $employeeB;
    private Quinzaine $quinzaineB;
    private Bloc $blocA;
    private Bloc $blocB;
    private Operation $operationA;
    private Operation $operationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farmA = Farm::create(['name' => 'Farm A']);
        $this->farmB = Farm::create(['name' => 'Farm B']);

        $this->enterpriseA = Enterprise::create([
            'farm_id' => $this->farmA->id,
            'name' => 'Enterprise A',
            'contract_type' => 'avec_contrat',
            'default_brut_rate' => 97.44,
        ]);

        $this->enterpriseB = Enterprise::create([
            'farm_id' => $this->farmB->id,
            'name' => 'Enterprise B',
            'contract_type' => 'sans_contrat',
            'default_brut_rate' => 90.87,
        ]);

        $this->managerA = User::factory()->create([
            'role' => 'farm_manager',
            'farm_id' => $this->farmA->id,
        ]);

        $this->dataEntryA = User::factory()->create([
            'role' => 'data_entry',
            'farm_id' => $this->farmA->id,
        ]);

        $this->employeeA = Employee::create([
            'enterprise_id' => $this->enterpriseA->id,
            'matricule' => 'A-1',
            'full_name' => 'Employee A',
            'type' => 'persea',
            'base_rate' => 97.44,
            'is_active' => true,
        ]);

        $this->employeeB = Employee::create([
            'enterprise_id' => $this->enterpriseB->id,
            'matricule' => 'B-1',
            'full_name' => 'Employee B',
            'type' => 'hafila',
            'base_rate' => 90.87,
            'is_active' => true,
        ]);

        $this->quinzaineB = Quinzaine::create([
            'enterprise_id' => $this->enterpriseB->id,
            'label' => '1QZ Test',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-15',
            'is_closed' => false,
        ]);

        $this->blocA = Bloc::create(['farm_id' => $this->farmA->id, 'name' => 'Bloc A']);
        $this->blocB = Bloc::create(['farm_id' => $this->farmB->id, 'name' => 'Bloc B']);
        $this->operationA = Operation::create(['farm_id' => $this->farmA->id, 'name' => 'Op A']);
        $this->operationB = Operation::create(['farm_id' => $this->farmB->id, 'name' => 'Op B']);

        PointageRecord::create([
            'employee_id' => $this->employeeB->id,
            'quinzaine_id' => $this->quinzaineB->id,
            'operation_id' => $this->operationB->id,
            'bloc_id' => $this->blocB->id,
            'date' => '2026-01-05',
            'hours' => 0,
            'is_jf' => false,
            'rate' => 90.87,
            'brut' => 90.87,
            'net' => 90.87,
        ]);
    }

    public function test_farm_manager_cannot_read_another_farms_analytics_via_enterprise_id(): void
    {
        $this->actingAs($this->managerA)
            ->get('/analytics?enterprise_id=' . $this->enterpriseB->id)
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_read_another_farms_payroll_history_via_enterprise_id(): void
    {
        $this->actingAs($this->managerA)
            ->get('/payroll-history?enterprise_id=' . $this->enterpriseB->id)
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_download_another_farms_payslip(): void
    {
        $this->actingAs($this->managerA)
            ->get('/payroll/payslip/' . $this->employeeB->id . '/' . $this->quinzaineB->id)
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_download_another_farms_general_payslip(): void
    {
        $this->actingAs($this->managerA)
            ->get('/payroll/general-payslip/' . $this->quinzaineB->id)
            ->assertForbidden();
    }

    public function test_farm_manager_can_still_read_their_own_farms_analytics(): void
    {
        $this->actingAs($this->managerA)
            ->get('/analytics?enterprise_id=' . $this->enterpriseA->id)
            ->assertOk();
    }

    public function test_data_entry_cannot_create_a_farm(): void
    {
        $this->actingAs($this->dataEntryA)
            ->post('/farms', ['name' => 'New Farm'])
            ->assertForbidden();
    }

    public function test_data_entry_cannot_delete_a_farm(): void
    {
        $this->actingAs($this->dataEntryA)
            ->delete('/farms/' . $this->farmA->id)
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_delete_another_farm(): void
    {
        $this->actingAs($this->managerA)
            ->delete('/farms/' . $this->farmB->id)
            ->assertForbidden();
    }

    public function test_data_entry_cannot_create_a_user(): void
    {
        $this->actingAs($this->dataEntryA)
            ->post('/users', [
                'name' => 'Escalated', 'email' => 'escalated@test.com', 'password' => 'password123',
                'role' => 'farm_manager', 'farm_id' => $this->farmA->id,
            ])
            ->assertForbidden();
    }

    public function test_data_entry_cannot_create_an_enterprise(): void
    {
        $this->actingAs($this->dataEntryA)
            ->post('/enterprises', [
                'farm_id' => $this->farmA->id, 'name' => 'New Division',
                'contract_type' => 'avec_contrat', 'default_brut_rate' => 90,
            ])
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_update_another_farms_enterprise(): void
    {
        $this->actingAs($this->managerA)
            ->patch('/enterprises/' . $this->enterpriseB->id, [
                'name' => 'Hijacked', 'default_brut_rate' => 1, 'contract_type' => 'sans_contrat',
            ])
            ->assertForbidden();
    }

    public function test_data_entry_cannot_close_a_quinzaine(): void
    {
        $ownQuinzaine = Quinzaine::create([
            'enterprise_id' => $this->enterpriseA->id,
            'label' => '1QZ Test A', 'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false,
        ]);

        $this->actingAs($this->dataEntryA)
            ->post('/settings/quinzaine/' . $ownQuinzaine->id . '/close')
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_close_another_farms_quinzaine(): void
    {
        $this->actingAs($this->managerA)
            ->post('/settings/quinzaine/' . $this->quinzaineB->id . '/close')
            ->assertForbidden();
    }

    public function test_duplicate_quinzaine_is_rejected(): void
    {
        Quinzaine::create([
            'enterprise_id' => $this->enterpriseA->id,
            'label' => '1QZ Existing', 'start_date' => '2026-02-01', 'end_date' => '2026-02-15', 'is_closed' => false,
        ]);

        $this->actingAs($this->managerA)
            ->post('/settings/quinzaine', [
                'enterprise_id' => $this->enterpriseA->id,
                'start_date' => '2026-02-01', 'end_date' => '2026-02-15',
            ])
            ->assertStatus(422);
    }

    public function test_farm_manager_cannot_delete_another_farms_bloc(): void
    {
        $this->actingAs($this->managerA)
            ->delete('/farms/blocs/' . $this->blocB->id)
            ->assertForbidden();
    }

    public function test_data_entry_cannot_delete_a_bloc(): void
    {
        $this->actingAs($this->dataEntryA)
            ->delete('/farms/blocs/' . $this->blocA->id)
            ->assertForbidden();
    }

    public function test_farm_manager_can_delete_their_own_farms_bloc(): void
    {
        $this->actingAs($this->managerA)
            ->delete('/farms/blocs/' . $this->blocA->id)
            ->assertRedirect();
    }

    public function test_farm_manager_cannot_delete_another_farms_operation(): void
    {
        $this->actingAs($this->managerA)
            ->delete('/farms/operations/' . $this->operationB->id)
            ->assertForbidden();
    }

    public function test_super_admin_can_delete_a_farm_with_related_stock_data(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'farm_id' => null]);
        $vehicle = \App\Models\Vehicle::create([
            'farm_id' => $this->farmA->id, 'name' => 'V1', 'plate_number' => 'DEL-1',
            'type' => 'tractor', 'fuel_type' => 'diesel', 'is_active' => true,
        ]);
        $product = \App\Models\Product::create([
            'farm_id' => $this->farmA->id, 'name' => 'P1', 'category' => 'fuel',
            'unit_type' => 'liters', 'min_stock_level' => 10, 'unit_cost' => 5, 'is_active' => true,
        ]);
        \App\Models\ManualStockEntry::create([
            'farm_id' => $this->farmA->id, 'product_id' => $product->id, 'entry_type' => 'consumption',
            'quantity' => 5, 'vehicle_id' => $vehicle->id, 'bloc_id' => $this->blocA->id,
            'date' => '2026-01-01', 'entered_by' => $superAdmin->id, 'is_verified' => false,
        ]);

        $this->actingAs($superAdmin)
            ->withSession(['active_farm_id' => $this->farmA->id])
            ->delete('/farms/' . $this->farmA->id)
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('farms', ['id' => $this->farmA->id]);
    }

    public function test_data_entry_cannot_create_an_employee(): void
    {
        $this->actingAs($this->dataEntryA)
            ->post('/employees', [
                'matricule' => 'NEW-1', 'full_name' => 'New Employee', 'type' => 'hafila',
                'base_rate' => 90, 'enterprise_id' => $this->enterpriseA->id,
            ])
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_create_an_employee_in_another_farms_enterprise(): void
    {
        $this->actingAs($this->managerA)
            ->post('/employees', [
                'matricule' => 'NEW-2', 'full_name' => 'New Employee', 'type' => 'hafila',
                'base_rate' => 90, 'enterprise_id' => $this->enterpriseB->id,
            ])
            ->assertForbidden();
    }

    public function test_data_entry_cannot_delete_an_employee(): void
    {
        $this->actingAs($this->dataEntryA)
            ->delete('/employees/' . $this->employeeA->id)
            ->assertForbidden();
    }

    public function test_data_entry_cannot_toggle_employee_active(): void
    {
        $this->actingAs($this->dataEntryA)
            ->post('/employees/' . $this->employeeA->id . '/toggle-active')
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_delete_another_farms_employee(): void
    {
        $this->actingAs($this->managerA)
            ->delete('/employees/' . $this->employeeB->id)
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_update_another_farms_employee(): void
    {
        $this->actingAs($this->managerA)
            ->put('/employees/' . $this->employeeB->id, [
                'matricule' => 'B-1', 'full_name' => 'Hijacked', 'type' => 'hafila',
                'base_rate' => 1, 'enterprise_id' => $this->enterpriseB->id,
            ])
            ->assertForbidden();
    }

    public function test_farm_manager_cannot_reassign_employee_to_another_farms_enterprise(): void
    {
        $this->actingAs($this->managerA)
            ->put('/employees/' . $this->employeeA->id, [
                'matricule' => 'A-1', 'full_name' => 'Employee A', 'type' => 'persea',
                'base_rate' => 97.44, 'enterprise_id' => $this->enterpriseB->id,
            ])
            ->assertForbidden();
    }

    public function test_farm_manager_can_update_their_own_farms_employee(): void
    {
        $this->actingAs($this->managerA)
            ->put('/employees/' . $this->employeeA->id, [
                'matricule' => 'A-1', 'full_name' => 'Employee A Updated', 'type' => 'persea',
                'base_rate' => 97.44, 'enterprise_id' => $this->enterpriseA->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('employees', ['id' => $this->employeeA->id, 'full_name' => 'Employee A Updated']);
    }

    public function test_data_entry_can_update_their_own_farms_employee(): void
    {
        $this->actingAs($this->dataEntryA)
            ->put('/employees/' . $this->employeeA->id, [
                'matricule' => 'A-1', 'full_name' => 'Employee A Updated By Data Entry', 'type' => 'persea',
                'base_rate' => 97.44, 'enterprise_id' => $this->enterpriseA->id,
            ])
            ->assertRedirect();
    }

    public function test_data_entry_cannot_update_another_farms_employee(): void
    {
        $this->actingAs($this->dataEntryA)
            ->put('/employees/' . $this->employeeB->id, [
                'matricule' => 'B-1', 'full_name' => 'Hijacked', 'type' => 'hafila',
                'base_rate' => 1, 'enterprise_id' => $this->enterpriseB->id,
            ])
            ->assertForbidden();
    }

    public function test_cannot_log_a_harvest_against_another_farms_bloc(): void
    {
        $this->actingAs($this->dataEntryA)
            ->withSession(['active_farm_id' => $this->farmA->id])
            ->post('/harvests', [
                'bloc_id' => $this->blocB->id, 'date' => '2026-01-05', 'variety' => 'Hass', 'boxes_count' => 10,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('harvests', ['bloc_id' => $this->blocB->id]);
    }

    public function test_can_log_a_harvest_against_own_farms_bloc(): void
    {
        $this->actingAs($this->dataEntryA)
            ->post('/harvests', [
                'bloc_id' => $this->blocA->id, 'date' => '2026-01-05', 'variety' => 'Hass', 'boxes_count' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('harvests', ['bloc_id' => $this->blocA->id, 'farm_id' => $this->farmA->id]);
    }
}
