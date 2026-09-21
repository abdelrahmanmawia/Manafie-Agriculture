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

    public function test_matricule_is_generated_sequentially_per_farm_and_ignores_client_input(): void
    {
        $farmA = Farm::create(['name' => 'Farm A']);
        $farmB = Farm::create(['name' => 'Farm B']);
        $entA = Enterprise::create(['farm_id' => $farmA->id, 'name' => 'A', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $entB = Enterprise::create(['farm_id' => $farmB->id, 'name' => 'B', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $managerA = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farmA->id]);
        $managerB = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farmB->id]);

        foreach (['One', 'Two'] as $name) {
            $this->actingAs($managerA)->post('/employees', [
                'matricule' => 'HACK', 'full_name' => $name, 'base_rate' => 97.44, 'enterprise_id' => $entA->id,
            ])->assertRedirect();
        }
        $this->actingAs($managerB)->post('/employees', [
            'full_name' => 'Other farm', 'base_rate' => 97.44, 'enterprise_id' => $entB->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('employees', ['farm_id' => $farmA->id, 'full_name' => 'One', 'matricule' => '001']);
        $this->assertDatabaseHas('employees', ['farm_id' => $farmA->id, 'full_name' => 'Two', 'matricule' => '002']);
        $this->assertDatabaseHas('employees', ['farm_id' => $farmB->id, 'full_name' => 'Other farm', 'matricule' => '001']);
    }

    public function test_matricule_cannot_be_changed_by_an_update(): void
    {
        $farm = Farm::create(['name' => 'Farm A']);
        $ent = Enterprise::create(['farm_id' => $farm->id, 'name' => 'A', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);
        $employee = Employee::create([
            'farm_id' => $farm->id, 'enterprise_id' => $ent->id, 'matricule' => '007', 'full_name' => 'Person',
            'type' => 'persea', 'base_rate' => 97.44, 'is_active' => true,
        ]);

        $this->actingAs($manager)->put('/employees/' . $employee->id, [
            'matricule' => '999', 'full_name' => 'Person', 'base_rate' => 97.44, 'enterprise_id' => $ent->id,
        ])->assertRedirect();

        $this->assertSame('007', $employee->fresh()->matricule);
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
                'cin' => 'G546734', 'full_name' => 'Different Person',
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
                'cin' => 'G546734', 'full_name' => 'AHANNI AZIZA',
                'base_rate' => 97.44, 'type' => 'persea', 'enterprise_id' => $enterpriseY->id,
            ])
            ->assertRedirect();

        $employee->refresh();
        $this->assertEquals($enterpriseY->id, $employee->enterprise_id);
        $this->assertEquals($farm->id, $employee->farm_id);
    }
}
