<?php

namespace Tests\Feature;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The "Aujourd'hui" page: each équipe's presence that day, and each division's "à préciser". */
class AujourdhuiTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $a;

    private Enterprise $b;

    private Quinzaine $qa;

    private Quinzaine $qb;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        foreach (['a' => 'A', 'b' => 'B'] as $prop => $name) {
            $this->{$prop} = Enterprise::create(['farm_id' => $this->farm->id, 'name' => $name, 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
            $this->{'q'.$prop} = Quinzaine::create(['enterprise_id' => $this->{$prop}->id, 'label' => '1ERE Qz novembre 2026', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15', 'is_closed' => false]);
        }
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);

        // Équipe ONE spans both divisions: 3 members, 2 present on the 2nd (one still à préciser).
        // Équipe TWO: nobody pointed. One worker in no équipe, present.
        $one = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'ONE']);
        $two = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'TWO']);
        Equipe::create(['farm_id' => $this->farm->id, 'name' => 'EMPTY']);
        $op = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Irrigation', 'abbreviation' => 'Irr']);
        $bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        $worker = fn ($m, $division, $equipe) => Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => $division->id, 'matricule' => $m,
            'full_name' => "W{$m}", 'base_rate' => 97.44, 'is_active' => true, 'equipe_id' => $equipe?->id]);
        $day = fn ($e, $q, $withOp, $date = '2026-11-02') => PointageRecord::create(['employee_id' => $e->id, 'quinzaine_id' => $q->id, 'date' => $date, 'present' => true,
            'operation_id' => $withOp ? $op->id : null, 'bloc_id' => $withOp ? $bloc->id : null, 'hours' => 0, 'rate' => 97.44, 'brut' => 97.44, 'net' => 100]);

        $day($worker('1', $this->a, $one), $this->qa, true);
        $day($worker('2', $this->b, $one), $this->qb, false);
        $worker('3', $this->a, $one);
        $worker('4', $this->a, $two);
        $day($worker('5', $this->b, null), $this->qb, true);
        $day(Employee::where('matricule', '4')->sole(), $this->qa, false, '2026-11-03'); // another day: à préciser on the period only
    }

    public function test_each_equipe_shows_who_is_pointed_that_day(): void
    {
        $this->actingAs($this->manager)->get(route('pointage.today', ['date' => '2026-11-02']))->assertInertia(fn ($p) => $p
            ->component('Pointage/Aujourdhui')
            ->where('date', '2026-11-02')
            ->where('reference.id', $this->qa->id)
            ->where('equipes', fn ($list) => collect($list)->map(fn ($e) => [$e['name'], $e['effectif'], $e['presents'], $e['a_preciser']])->all() === [
                ['ONE', 3, 2, 1], ['TWO', 1, 0, 0], ['Sans équipe', 1, 1, 0],
            ])
            ->where('totals', ['effectif' => 5, 'presents' => 3, 'a_preciser' => 1]));
    }

    public function test_each_division_shows_its_days_a_preciser_that_day_and_over_the_period(): void
    {
        $this->actingAs($this->manager)->get(route('pointage.today', ['date' => '2026-11-02']))->assertInertia(fn ($p) => $p
            ->where('divisions', fn ($list) => collect($list)->map(fn ($d) => [$d['name'], $d['presents'], $d['a_preciser'], $d['a_preciser_periode']])->all() === [
                ['A', 1, 0, 1], ['B', 2, 1, 1],
            ]));
    }

    public function test_a_day_outside_every_period_says_so(): void
    {
        $this->actingAs($this->manager)->get(route('pointage.today', ['date' => '2026-12-01']))
            ->assertInertia(fn ($p) => $p->where('reference', null)->where('divisions', []));
    }

    public function test_a_division_locked_user_sees_only_their_division(): void
    {
        $pointeur = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'enterprise_id' => $this->a->id, 'can_access_pointage' => true]);

        $this->actingAs($pointeur)->get(route('pointage.today', ['date' => '2026-11-02']))->assertInertia(fn ($p) => $p
            ->where('divisions', fn ($list) => collect($list)->pluck('name')->all() === ['A'])
            ->where('totals.effectif', 3));
    }
}
