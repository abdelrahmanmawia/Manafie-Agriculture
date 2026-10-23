<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\TransportCompany;
use App\Models\TransportLocation;
use App\Models\TransportVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipeTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $enterprise;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->enterprise = Enterprise::create([
            'farm_id' => $this->farm->id, 'name' => 'A', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
    }

    private function employee(string $name, ?Equipe $equipe = null, ?TransportLocation $residence = null): Employee
    {
        return Employee::create([
            'farm_id' => $this->farm->id, 'enterprise_id' => $this->enterprise->id, 'matricule' => $name, 'full_name' => $name,
            'type' => 'persea', 'base_rate' => 97.44, 'is_active' => true,
            'equipe_id' => $equipe?->id, 'residence_location_id' => $residence?->id,
        ]);
    }

    private function equipe(string $name, array $attributes = []): Equipe
    {
        return Equipe::create(['farm_id' => $this->farm->id, 'name' => $name] + $attributes);
    }

    public function test_creating_an_equipe_makes_its_chef_a_member(): void
    {
        $chef = $this->employee('Chef');

        $this->actingAs($this->manager)->post(route('equipes.store'), ['name' => 'Equipe 1', 'chef_employee_id' => $chef->id])
            ->assertSessionHasNoErrors();

        $equipe = Equipe::sole();
        $this->assertSame($this->farm->id, $equipe->farm_id);
        $this->assertSame($equipe->id, $chef->fresh()->equipe_id);
    }

    public function test_a_chef_moved_to_another_equipe_stops_being_chef_of_the_first(): void
    {
        $first = $this->equipe('First');
        $chef = $this->employee('Chef', $first);
        $first->update(['chef_employee_id' => $chef->id]);
        $second = $this->equipe('Second');

        $this->actingAs($this->manager)->put(route('equipes.update', $second), ['chef_employee_id' => $chef->id])
            ->assertSessionHasNoErrors();

        $this->assertNull($first->fresh()->chef_employee_id);
        $this->assertSame($second->id, $chef->fresh()->equipe_id);
    }

    public function test_equipe_names_are_unique_per_farm(): void
    {
        $this->equipe('Equipe 1');

        $this->actingAs($this->manager)->post(route('equipes.store'), ['name' => 'Equipe 1'])->assertSessionHasErrors('name');
    }

    public function test_sync_members_sets_exactly_the_checked_workers(): void
    {
        $equipe = $this->equipe('Equipe 1');
        $other = $this->equipe('Other');
        $stays = $this->employee('Stays', $equipe);
        $leaves = $this->employee('Leaves', $equipe);
        $moves = $this->employee('Moves', $other);
        $equipe->update(['chef_employee_id' => $leaves->id]);
        $other->update(['chef_employee_id' => $moves->id]);

        $this->actingAs($this->manager)->put(route('equipes.members', $equipe), ['employee_ids' => [$stays->id, $moves->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame($equipe->id, $stays->fresh()->equipe_id);
        $this->assertSame($equipe->id, $moves->fresh()->equipe_id);
        $this->assertNull($leaves->fresh()->equipe_id);
        // The chef who left is no longer its chef, and the one who came over is no longer chef of their old team.
        $this->assertNull($equipe->fresh()->chef_employee_id);
        $this->assertNull($other->fresh()->chef_employee_id);
    }

    public function test_sync_members_rejects_another_farms_worker(): void
    {
        $equipe = $this->equipe('Equipe 1');
        $otherFarm = Farm::create(['name' => 'Other']);
        $foreign = Employee::create([
            'farm_id' => $otherFarm->id,
            'enterprise_id' => Enterprise::create(['farm_id' => $otherFarm->id, 'name' => 'X', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 1])->id,
            'matricule' => 'F', 'full_name' => 'Foreign', 'type' => 'persea', 'base_rate' => 1, 'is_active' => true,
        ]);

        $this->actingAs($this->manager)->put(route('equipes.members', $equipe), ['employee_ids' => [$foreign->id]])
            ->assertSessionHasErrors('employee_ids.0');

        $this->assertNull($foreign->fresh()->equipe_id);
    }

    public function test_an_equipe_with_members_cannot_be_deleted(): void
    {
        $equipe = $this->equipe('Equipe 1');
        $member = $this->employee('Member', $equipe);

        $this->actingAs($this->manager)->delete(route('equipes.destroy', $equipe))->assertSessionHasErrors('equipe');
        $this->assertModelExists($equipe);

        $member->update(['equipe_id' => null]);
        $this->actingAs($this->manager)->delete(route('equipes.destroy', $equipe))->assertSessionHasNoErrors();
        $this->assertModelMissing($equipe);
    }

    public function test_a_division_locked_user_cannot_reshape_equipes(): void
    {
        $equipe = $this->equipe('Equipe 1');
        $locked = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'enterprise_id' => $this->enterprise->id]);

        $this->actingAs($locked)->get(route('equipes.index'))->assertOk()->assertInertia(fn ($p) => $p->where('canManage', false));
        $this->actingAs($locked)->post(route('equipes.store'), ['name' => 'New'])->assertForbidden();
        $this->actingAs($locked)->put(route('equipes.update', $equipe), ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($locked)->put(route('equipes.members', $equipe), ['employee_ids' => []])->assertForbidden();
        $this->actingAs($locked)->delete(route('equipes.destroy', $equipe))->assertForbidden();
    }

    public function test_another_farms_equipe_is_forbidden(): void
    {
        $foreign = Equipe::create(['farm_id' => Farm::create(['name' => 'Other'])->id, 'name' => 'Foreign']);

        $this->actingAs($this->manager)->put(route('equipes.update', $foreign), ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($this->manager)->delete(route('equipes.destroy', $foreign))->assertForbidden();
    }

    public function test_a_vehicles_riders_are_the_members_of_the_equipes_riding_it(): void
    {
        $company = TransportCompany::create(['farm_id' => $this->farm->id, 'name' => 'Co']);
        $van = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $company->id, 'code' => 'T1']);
        $near = TransportLocation::create(['farm_id' => $this->farm->id, 'name' => 'Near', 'price_per_person' => 10]);
        $far = TransportLocation::create(['farm_id' => $this->farm->id, 'name' => 'Far', 'price_per_person' => 25]);
        $this->employee('A', $this->equipe('One', ['transport_vehicle_id' => $van->id]), $near);
        $this->employee('B', $this->equipe('Two', ['transport_vehicle_id' => $van->id]), $far);
        $this->employee('C', $this->equipe('Walks'), $far);

        $van->load('employees.residenceLocation');

        $this->assertCount(2, $van->employees);
        $this->assertEquals(35, $van->netPerDay());
    }

    public function test_a_vehicle_still_ridden_by_an_equipe_cannot_be_deleted(): void
    {
        $company = TransportCompany::create(['farm_id' => $this->farm->id, 'name' => 'Co']);
        $van = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $company->id, 'code' => 'T1']);
        $this->equipe('One', ['transport_vehicle_id' => $van->id]);

        $this->actingAs($this->manager)->delete(route('transport.vehicles.destroy', $van));

        $this->assertModelExists($van);
    }
}
