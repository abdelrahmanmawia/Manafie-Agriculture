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

    public function test_toutes_lists_every_worker_of_every_division_in_one_grid(): void
    {
        [$manager, $qa, $qb, , $a, $b, $c] = $this->setUpFarm();
        // Gone since, but with a day in the period: stays listed, so the totals still add up.
        $parti = Employee::create(['farm_id' => $a->farm_id, 'enterprise_id' => $a->enterprise_id, 'matricule' => 'P', 'full_name' => 'Parti', 'base_rate' => 97.44, 'is_active' => false]);
        // Inactive with no day in the period, and a worker of another site: not listed.
        Employee::create(['farm_id' => $a->farm_id, 'enterprise_id' => $a->enterprise_id, 'matricule' => 'V', 'full_name' => 'Vieux', 'base_rate' => 97.44, 'is_active' => false]);
        Employee::create(['farm_id' => $a->farm_id, 'enterprise_id' => $a->enterprise_id, 'matricule' => 'S', 'full_name' => 'Site', 'base_rate' => 97.44, 'is_active' => true, 'salaire_mensuel' => 3500, 'site_separe' => 'ASSILAH', 'salaire_brut_mensuel' => 3422.72]);
        $jour = fn ($e, $q, $date, $net) => \App\Models\PointageRecord::create(['employee_id' => $e->id, 'quinzaine_id' => $q->id, 'date' => $date, 'present' => true, 'hours' => 0, 'rate' => 97.44, 'brut' => 97.44, 'net' => $net]);
        $jour($parti, $qa, '2026-09-16', 90);
        $jour($b, $qb, '2026-09-16', 100);
        $jour($a, $qa, '2026-09-17', 110);

        $this->actingAs($manager)->get(route('pointage.grid', ['quinzaine' => $qa->id, 'mode' => 'equipe', 'equipe' => 'toutes']))
            ->assertInertia(fn ($p) => $p
                ->where('mode', 'equipe')
                ->where('equipeId', 'toutes')
                ->has('employees', 4)
                ->where('employees', fn ($e) => collect($e)->pluck('full_name')->sort()->values()->all() === ['A Member', 'B Member', 'C Loner', 'Parti'])
                // Each row in its own division's quinzaine, its division on the row.
                ->where('employees', fn ($e) => collect($e)->firstWhere('id', $b->id)['quinzaine_id'] === $qb->id && collect($e)->firstWhere('id', $b->id)['enterprise']['name'] === 'B')
                ->where('employees', fn ($e) => collect($e)->firstWhere('id', $a->id)['quinzaine_id'] === $qa->id)
                ->where("existingRecords.{$b->id}.2026-09-16.0.net", fn ($n) => abs($n - 100) < 0.01));

        // The footer sums the whole farm, the same whichever division the Grid was opened from.
        foreach ([$qa, $qb] as $q) {
            $total = $this->actingAs($manager)->getJson(route('pointage.summary', ['quinzaine' => $q->id, 'toutes' => 1]))->assertOk()->json('daily_totals');
            $this->assertEqualsWithDelta(300, array_sum($total), 0.01);
        }
    }

    public function test_a_division_locked_user_sees_only_their_division_in_toutes(): void
    {
        [, $qa, $qb, , $a, $b, $c] = $this->setUpFarm();
        $locked = User::factory()->create(['role' => 'data_entry', 'farm_id' => $a->farm_id, 'enterprise_id' => $a->enterprise_id]);
        \App\Models\PointageRecord::create(['employee_id' => $b->id, 'quinzaine_id' => $qb->id, 'date' => '2026-09-16', 'present' => true, 'hours' => 0, 'rate' => 97.44, 'brut' => 97.44, 'net' => 100]);

        $this->actingAs($locked)->get(route('pointage.grid', ['quinzaine' => $qa->id, 'mode' => 'equipe', 'equipe' => 'toutes']))
            ->assertInertia(fn ($p) => $p->where('employees', fn ($e) => collect($e)->pluck('id')->sort()->values()->all() === collect([$a->id, $c->id])->sort()->values()->all()));
        $total = $this->actingAs($locked)->getJson(route('pointage.summary', ['quinzaine' => $qa->id, 'toutes' => 1]))->assertOk()->json('daily_totals');
        $this->assertEqualsWithDelta(0, array_sum($total), 0.01);
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
