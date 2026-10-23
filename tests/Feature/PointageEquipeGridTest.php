<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\Quinzaine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The pointage grid can list a chef d'équipe's team even when it spans several divisions. */
class PointageEquipeGridTest extends TestCase
{
    use RefreshDatabase;

    private function setUpFarm(): array
    {
        $farm = Farm::create(['name' => 'Farm']);
        $mk = fn ($name) => Enterprise::create([
            'farm_id' => $farm->id, 'name' => $name, 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $entA = $mk('A');
        $entB = $mk('B');
        $qa = Quinzaine::create(['enterprise_id' => $entA->id, 'label' => 'Q', 'start_date' => '2026-09-16', 'end_date' => '2026-09-30', 'is_closed' => false]);
        $qb = Quinzaine::create(['enterprise_id' => $entB->id, 'label' => 'Q', 'start_date' => '2026-09-16', 'end_date' => '2026-09-30', 'is_closed' => true]);
        $equipe = Equipe::create(['farm_id' => $farm->id, 'name' => 'Equipe 1']);
        $emp = fn ($ent, $name, $equipe) => Employee::create([
            'farm_id' => $farm->id, 'enterprise_id' => $ent->id, 'matricule' => $name, 'full_name' => $name,
            'type' => 'persea', 'base_rate' => 97.44, 'is_active' => true, 'equipe_id' => $equipe?->id,
        ]);
        $a = $emp($entA, 'A Member', $equipe);
        $b = $emp($entB, 'B Member', $equipe);
        $c = $emp($entA, 'C Loner', null);

        return [User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]), $qa, $qb, $equipe, $a, $b, $c];
    }

    public function test_equipe_mode_lists_members_across_divisions_with_their_own_quinzaine(): void
    {
        [$manager, $qa, $qb, $equipe] = $this->setUpFarm();

        $this->actingAs($manager)->get(route('pointage.grid', ['quinzaine' => $qa->id, 'mode' => 'equipe', 'equipe' => $equipe->id]))
            ->assertInertia(fn ($p) => $p
                ->where('mode', 'equipe')
                ->where('equipeId', $equipe->id)
                ->has('employees', 2)
                ->where('employees.0.quinzaine_id', $qa->id)
                ->where('employees.1.quinzaine_id', $qb->id)
                ->where('employees.1.quinzaine_closed', true));
    }

    public function test_the_chef_is_listed_first(): void
    {
        [$manager, $qa, , $equipe, , $b] = $this->setUpFarm();
        $equipe->update(['chef_employee_id' => $b->id]);

        $this->actingAs($manager)->get(route('pointage.grid', ['quinzaine' => $qa->id, 'mode' => 'equipe', 'equipe' => $equipe->id]))
            ->assertInertia(fn ($p) => $p->has('employees', 2)->where('employees.0.id', $b->id));
    }

    public function test_none_lists_the_workers_in_no_equipe(): void
    {
        [$manager, $qa, , , , , $c] = $this->setUpFarm();

        $this->actingAs($manager)->get(route('pointage.grid', ['quinzaine' => $qa->id, 'mode' => 'equipe', 'equipe' => 'none']))
            ->assertInertia(fn ($p) => $p->where('equipeId', 'none')->has('employees', 1)->where('employees.0.id', $c->id));
    }

    public function test_default_mode_stays_the_divisions_own_employees(): void
    {
        [$manager, $qa] = $this->setUpFarm();

        $this->actingAs($manager)->get(route('pointage.grid', $qa->id))
            ->assertInertia(fn ($p) => $p->where('mode', 'division')->has('employees', 2)->has('equipes', 1));
    }

    public function test_equipe_mode_without_an_equipe_is_empty(): void
    {
        [$manager, $qa] = $this->setUpFarm();

        $this->actingAs($manager)->get(route('pointage.grid', ['quinzaine' => $qa->id, 'mode' => 'equipe']))
            ->assertInertia(fn ($p) => $p->where('mode', 'equipe')->has('employees', 0));
    }

    public function test_another_farms_equipe_lists_nobody(): void
    {
        [$manager, $qa] = $this->setUpFarm();
        $other = Equipe::create(['farm_id' => Farm::create(['name' => 'Other'])->id, 'name' => 'Foreign']);

        $this->actingAs($manager)->get(route('pointage.grid', ['quinzaine' => $qa->id, 'mode' => 'equipe', 'equipe' => $other->id]))
            ->assertInertia(fn ($p) => $p->has('employees', 0));
    }

    public function test_enterprise_scoped_user_only_sees_own_division_members(): void
    {
        [, $qa, , $equipe, $a] = $this->setUpFarm();
        $scoped = User::factory()->create(['role' => 'data_entry', 'farm_id' => $qa->enterprise->farm_id, 'enterprise_id' => $qa->enterprise_id]);

        $this->actingAs($scoped)->get(route('pointage.grid', ['quinzaine' => $qa->id, 'mode' => 'equipe', 'equipe' => $equipe->id]))
            ->assertInertia(fn ($p) => $p->has('employees', 1)->where('employees.0.id', $a->id));
    }
}
