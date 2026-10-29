<?php

namespace Tests\Feature;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageChange;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Moving a worker to another division takes their open-period days along; the Grid shows a
 * worker's days pointed in another division instead of an empty cell.
 */
class ChangementDivisionTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $divA;

    private Enterprise $divB;

    private Quinzaine $qa;

    private Quinzaine $qb;

    private User $pointeur;

    private Operation $dm;

    private Bloc $b1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->divA = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'PERSEALAND', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        // B pays the plain brut (no contract): a moved day is repriced for it.
        $this->divB = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'NON DECLARE', 'contract_type' => 'sans_contrat', 'default_brut_rate' => 100]);
        $this->qa = Quinzaine::create(['enterprise_id' => $this->divA->id, 'label' => 'Q', 'start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'is_closed' => false]);
        $this->qb = Quinzaine::create(['enterprise_id' => $this->divB->id, 'label' => 'Q', 'start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'is_closed' => false]);
        $this->pointeur = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id]);
        $this->dm = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Desherbage manuel', 'abbreviation' => 'D.M']);
        $this->b1 = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
    }

    private function worker(string $name, Enterprise $division, ?Equipe $equipe = null): Employee
    {
        return Employee::create([
            'farm_id' => $this->farm->id, 'enterprise_id' => $division->id, 'matricule' => $name, 'full_name' => $name,
            'base_rate' => 97.44, 'complement' => 9.13, 'is_active' => true, 'equipe_id' => $equipe?->id,
        ]);
    }

    private function point(Employee $worker, Quinzaine $quinzaine, string $date, array $data = ['present' => true])
    {
        return $this->actingAs($this->pointeur)->post('/pointage/cell', $data + [
            'employee_id' => $worker->id, 'quinzaine_id' => $quinzaine->id, 'date' => $date,
        ]);
    }

    private function moveTo(Employee $worker, Enterprise $division)
    {
        return $this->actingAs($this->pointeur)->put(route('employees.update', $worker), [
            'full_name' => $worker->full_name, 'base_rate' => 97.44, 'complement' => 9.13, 'enterprise_id' => $division->id,
        ]);
    }

    public function test_a_move_takes_the_open_period_days_along_repriced_and_traced(): void
    {
        $worker = $this->worker('ABDELAZIZ', $this->divA);
        $this->point($worker, $this->qa, '2026-10-20')->assertSessionHasNoErrors();
        $this->point($worker, $this->qa, '2026-10-21', ['operation_id' => $this->dm->id, 'bloc_id' => $this->b1->id])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(100, (float) PointageRecord::where('employee_id', $worker->id)->value('net'), 0.01); // 90.87 + 9.13

        $this->moveTo($worker, $this->divB)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 jour(s)') && str_contains($m, 'NON DECLARE'));

        $days = PointageRecord::where('employee_id', $worker->id)->orderBy('date')->get();
        $this->assertSame([$this->qb->id, $this->qb->id], $days->pluck('quinzaine_id')->all());
        $this->assertSame([null, $this->dm->id], $days->pluck('operation_id')->all()); // kept as they were
        $this->assertTrue((bool) $days[0]->present);
        $this->assertEqualsWithDelta(100, (float) $days[0]->net, 0.01); // repriced for B: its plain brut
        // The move is in the change history.
        $this->assertSame(2, PointageChange::whereIn('pointage_record_id', $days->pluck('id'))->where('action', 'updated')
            ->whereJsonContains('apres->quinzaine_id', $this->qb->id)->count());
        // The day's dialog, opened from B, shows its whole history: entered in A, then moved.
        $lignes = collect($this->actingAs($this->pointeur)->getJson(route('pointage.history', [
            'employee_id' => $worker->id, 'quinzaine_id' => $this->qb->id, 'date' => '2026-10-20',
        ]))->assertOk()->json())->pluck('lignes')->flatten();
        $this->assertContains('Division : PERSEALAND → NON DECLARE', $lignes->all());
        $this->assertContains('Présence : oui', $lignes->all());

        // And the days can be pointed again from the new division.
        $this->point($worker, $this->qb, '2026-10-22')->assertSessionHasNoErrors();
    }

    public function test_closed_periods_and_days_the_new_division_cannot_take_stay_behind(): void
    {
        $worker = $this->worker('MINA', $this->divA);
        // An older, closed period of A keeps its day.
        $old = Quinzaine::create(['enterprise_id' => $this->divA->id, 'label' => 'Q1', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'is_closed' => false]);
        $this->point($worker, $old, '2026-10-10')->assertSessionHasNoErrors();
        $old->update(['is_closed' => true]);
        $this->point($worker, $this->qa, '2026-10-20')->assertSessionHasNoErrors();
        // B's period is closed for the 2nd half: nowhere to go.
        $this->qb->update(['is_closed' => true]);

        $this->moveTo($worker, $this->divB)
            ->assertSessionHas('success', fn ($m) => str_contains($m, "1 jour(s) restent dans l'ancienne division"));

        $this->assertSame(
            [$old->id, $this->qa->id],
            PointageRecord::where('employee_id', $worker->id)->orderBy('date')->pluck('quinzaine_id')->all()
        );
    }

    public function test_the_division_grid_offers_the_same_period_in_the_other_divisions(): void
    {
        $this->actingAs($this->pointeur)->get(route('pointage.grid', ['quinzaine' => $this->qa->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('divisions', fn ($d) => collect($d)->pluck('quinzaine_id')->sort()->values()->all() === [$this->qa->id, $this->qb->id]));

        // A division-locked user isn't offered the others.
        $locked = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'enterprise_id' => $this->divA->id]);
        $this->actingAs($locked)->get(route('pointage.grid', ['quinzaine' => $this->qa->id]))
            ->assertInertia(fn (Assert $page) => $page->where('divisions', fn ($d) => collect($d)->pluck('quinzaine_id')->all() === [$this->qa->id]));
    }

    public function test_the_equipe_grid_shows_a_day_pointed_in_another_division(): void
    {
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'PL HOMMES', 'is_active' => true]);
        $worker = $this->worker('ABDELAZIZ', $this->divA, $equipe);
        $this->point($worker, $this->qa, '2026-10-20')->assertSessionHasNoErrors();
        // Moved while B had no period: the day stays in A.
        $this->qb->delete();
        $this->moveTo($worker, $this->divB);
        $this->qb = Quinzaine::create(['enterprise_id' => $this->divB->id, 'label' => 'Q', 'start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'is_closed' => false]);
        $this->point($worker, $this->qb, '2026-10-21')->assertSessionHasNoErrors();

        $this->actingAs($this->pointeur)->get(route('pointage.grid', ['quinzaine' => $this->qb->id, 'mode' => 'equipe', 'equipe' => $equipe->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("existingRecords.{$worker->id}", fn ($days) => array_keys((array) collect($days)->all()) === ['2026-10-21'])
                ->where("ailleurs.{$worker->id}.2026-10-20.division", 'PERSEALAND')
                ->where("ailleurs.{$worker->id}.2026-10-20.present", true));

        // The division Grid of A still lists the day as its own.
        $this->actingAs($this->pointeur)->get(route('pointage.grid', ['quinzaine' => $this->qa->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has("existingRecords.{$worker->id}.2026-10-20")
                ->where("ailleurs.{$worker->id}.2026-10-21.division", 'NON DECLARE'));
    }
}
