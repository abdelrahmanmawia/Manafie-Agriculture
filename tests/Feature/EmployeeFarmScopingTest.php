<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for making Employee farm-scoped instead of enterprise-scoped: the real
 * archive data showed the same real worker (same CIN) moves between enterprises of the same
 * farm over time (Persealand -> Baraka Green -> Agri Interim), so identity had to move up to
 * the farm level, with CIN — not matricule, which isn't stable across a division's own
 * independent numbering — as the real dedup key.
 */
class EmployeeFarmScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_matricule_rejected_across_enterprises_of_the_same_farm(): void
    {
        $farm = Farm::create(['name' => 'Farm A']);
        $enterpriseX = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise X',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $enterpriseY = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise Y',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);

        Employee::create([
            'farm_id' => $farm->id, 'enterprise_id' => $enterpriseX->id, 'matricule' => '039', 'full_name' => 'Person X',
            'type' => 'persea', 'base_rate' => 97.44, 'is_active' => true,
        ]);

        $response = $this->actingAs($manager)
            ->post('/employees', [
                'matricule' => '039', 'full_name' => 'Person Y', 'type' => 'persea',
                'base_rate' => 97.44, 'enterprise_id' => $enterpriseY->id,
            ]);

        $response->assertSessionHasErrors('matricule');
        $this->assertDatabaseMissing('employees', ['full_name' => 'Person Y']);
    }

    public function test_same_matricule_allowed_across_different_farms(): void
    {
        $farmA = Farm::create(['name' => 'Farm A']);
        $farmB = Farm::create(['name' => 'Farm B']);
        $enterpriseA = Enterprise::create([
            'farm_id' => $farmA->id, 'name' => 'Enterprise A',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $enterpriseB = Enterprise::create([
            'farm_id' => $farmB->id, 'name' => 'Enterprise B',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $managerB = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farmB->id]);

        Employee::create([
            'farm_id' => $farmA->id, 'enterprise_id' => $enterpriseA->id, 'matricule' => '039', 'full_name' => 'Person A',
            'type' => 'persea', 'base_rate' => 97.44, 'is_active' => true,
        ]);

        $this->actingAs($managerB)
            ->post('/employees', [
                'matricule' => '039', 'full_name' => 'Person B', 'type' => 'persea',
                'base_rate' => 97.44, 'enterprise_id' => $enterpriseB->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'farm_id' => $farmB->id, 'matricule' => '039', 'full_name' => 'Person B',
        ]);
    }

    public function test_same_cin_rejected_within_the_same_farm(): void
    {
        $farm = Farm::create(['name' => 'Farm A']);
        $enterpriseX = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise X',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $enterpriseY = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise Y',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);

        Employee::create([
            'farm_id' => $farm->id, 'enterprise_id' => $enterpriseX->id, 'matricule' => 'X-1',
            'cin' => 'G546734', 'full_name' => 'AHANNI AZIZA',
            'type' => 'persea', 'base_rate' => 97.44, 'is_active' => true,
        ]);

        $response = $this->actingAs($manager)
            ->post('/employees', [
                'matricule' => 'Y-1', 'cin' => 'G546734', 'full_name' => 'Different Person',
                'base_rate' => 97.44, 'type' => 'persea', 'enterprise_id' => $enterpriseY->id,
            ]);

        $response->assertSessionHasErrors('cin');
        $this->assertDatabaseMissing('employees', ['full_name' => 'Different Person']);
    }

    public function test_employee_can_be_reassigned_to_a_different_enterprise_of_the_same_farm(): void
    {
        $farm = Farm::create(['name' => 'Farm A']);
        $enterpriseX = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise X',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $enterpriseY = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise Y',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);

        $employee = Employee::create([
            'farm_id' => $farm->id, 'enterprise_id' => $enterpriseX->id, 'matricule' => '039',
            'cin' => 'G546734', 'full_name' => 'AHANNI AZIZA',
            'type' => 'persea', 'base_rate' => 97.44, 'is_active' => true,
        ]);

        // Moving to Enterprise Y (same farm) keeps the same Employee row — farm_id never changes.
        $this->actingAs($manager)
            ->put('/employees/' . $employee->id, [
                'matricule' => '039', 'cin' => 'G546734', 'full_name' => 'AHANNI AZIZA',
                'base_rate' => 97.44, 'type' => 'persea', 'enterprise_id' => $enterpriseY->id,
            ])
            ->assertRedirect();

        $employee->refresh();
        $this->assertEquals($enterpriseY->id, $employee->enterprise_id);
        $this->assertEquals($farm->id, $employee->farm_id);
    }
}
