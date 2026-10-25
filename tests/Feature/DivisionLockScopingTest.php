<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Quinzaine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A data_entry locked to one division (users.enterprise_id) works on that division only — the
 * same rule every scope check now applies through Controller::assertQuinzaineInScope() /
 * assertEnterpriseInScope() / assertEmployeeInScope(). Some actions (closing or creating a
 * sibling division's quinzaine, deleting or deactivating a sibling division's employee) used to
 * check only the farm.
 */
class DivisionLockScopingTest extends TestCase
{
    use RefreshDatabase;

    private Enterprise $ownDivision;
    private Enterprise $siblingDivision;
    private User $pointeur;
    private Quinzaine $siblingQuinzaine;
    private Employee $siblingEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $farm = Farm::create(['name' => 'Farm']);
        $this->ownDivision = Enterprise::create(['farm_id' => $farm->id, 'name' => 'Own', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $this->siblingDivision = Enterprise::create(['farm_id' => $farm->id, 'name' => 'Sibling', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $this->pointeur = User::factory()->create([
            'role' => 'data_entry', 'farm_id' => $farm->id, 'enterprise_id' => $this->ownDivision->id, 'acces_pointage' => 'saisie',
        ]);
        $this->siblingQuinzaine = Quinzaine::create([
            'enterprise_id' => $this->siblingDivision->id, 'label' => '1QZ', 'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false,
        ]);
        $this->siblingEmployee = Employee::create([
            'farm_id' => $farm->id, 'enterprise_id' => $this->siblingDivision->id, 'matricule' => '001',
            'full_name' => 'Sibling Worker', 'base_rate' => 97.44, 'is_active' => true,
        ]);
    }

    public function test_cannot_close_a_sibling_divisions_quinzaine(): void
    {
        $this->actingAs($this->pointeur)->post("/settings/quinzaine/{$this->siblingQuinzaine->id}/close")->assertForbidden();

        $this->assertFalse($this->siblingQuinzaine->fresh()->is_closed);
    }

    public function test_cannot_create_a_quinzaine_for_a_sibling_division(): void
    {
        $this->actingAs($this->pointeur)->post('/settings/quinzaine', [
            'enterprise_id' => $this->siblingDivision->id, 'start_date' => '2026-02-01', 'end_date' => '2026-02-15',
        ])->assertForbidden();

        $this->assertSame(1, Quinzaine::count());
    }

    public function test_cannot_deactivate_or_delete_a_sibling_divisions_employee(): void
    {
        $this->actingAs($this->pointeur)->post("/employees/{$this->siblingEmployee->id}/toggle-active")->assertForbidden();
        $this->actingAs($this->pointeur)->delete("/employees/{$this->siblingEmployee->id}")->assertForbidden();

        $this->assertTrue((bool) $this->siblingEmployee->fresh()->is_active);
    }

    public function test_cannot_set_a_sibling_divisions_employee_residence(): void
    {
        $this->actingAs($this->pointeur)
            ->put("/transport/employees/{$this->siblingEmployee->id}/residence", ['residence_location_id' => null])
            ->assertForbidden();
    }

    public function test_cannot_print_a_sibling_divisions_badges_by_id(): void
    {
        $response = $this->actingAs($this->pointeur)->get(route('badges.print', ['employee_ids' => $this->siblingEmployee->id]));

        $response->assertOk();
        $this->assertNull($this->siblingEmployee->fresh()->badge_uuid); // never selected, so never minted
    }

    public function test_cannot_export_all_divisions(): void
    {
        $ownQuinzaine = Quinzaine::create([
            'enterprise_id' => $this->ownDivision->id, 'label' => '1QZ', 'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false,
        ]);

        $this->actingAs($this->pointeur)->get(route('pointage.exportAllDivisions', $ownQuinzaine))->assertForbidden();
    }

    public function test_a_farm_manager_can_filter_quinzaines_by_division(): void
    {
        Quinzaine::create([
            'enterprise_id' => $this->ownDivision->id, 'label' => 'OWN-QZ', 'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false,
        ]);
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->ownDivision->farm_id]);

        // Only the own division's quinzaine — the filter used to be ignored for a farm_manager.
        $this->actingAs($manager)->get('/pointage/quinzaines?enterprise_id=' . $this->ownDivision->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('quinzaines', 1)
                ->where('quinzaines.0.label', 'OWN-QZ'));
    }

    public function test_still_manages_their_own_division(): void
    {
        $ownQuinzaine = Quinzaine::create([
            'enterprise_id' => $this->ownDivision->id, 'label' => '1QZ', 'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false,
        ]);
        $ownEmployee = Employee::create([
            'farm_id' => $this->ownDivision->farm_id, 'enterprise_id' => $this->ownDivision->id, 'matricule' => '002',
            'full_name' => 'Own Worker', 'base_rate' => 97.44, 'is_active' => true,
        ]);

        $this->actingAs($this->pointeur)->post("/employees/{$ownEmployee->id}/toggle-active")->assertRedirect();
        $this->actingAs($this->pointeur)->post("/settings/quinzaine/{$ownQuinzaine->id}/close")->assertRedirect();

        $this->assertFalse((bool) $ownEmployee->fresh()->is_active);
        $this->assertTrue($ownQuinzaine->fresh()->is_closed);
    }
}
