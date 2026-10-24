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

/** The Quinzaines page: a period is opened for all divisions at once, each with its progress. */
class QuinzainesPageTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $a;

    private Enterprise $b;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->a = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'A', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $this->b = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'B', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
    }

    private function open(array $ids, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->post(route('settings.quinzaine'), [
            'enterprise_ids' => $ids, 'label' => '1ERE Qz novembre 2026', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15',
        ]);
    }

    public function test_a_farm_manager_opens_a_period_for_every_division_at_once(): void
    {
        $this->open([$this->a->id, $this->b->id])->assertSessionHas('success', 'Période ouverte pour A, B.');

        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], Quinzaine::pluck('enterprise_id')->all());
    }

    public function test_a_division_that_already_has_the_period_is_skipped_and_all_skipped_is_reported(): void
    {
        $this->open([$this->a->id]);

        $this->open([$this->a->id, $this->b->id])->assertSessionHas('success', 'Période ouverte pour B. Déjà ouverte : A.');
        $this->assertSame(2, Quinzaine::count());

        $this->open([$this->a->id, $this->b->id])->assertSessionHasErrors('quinzaine');
        $this->assertSame(2, Quinzaine::count());
    }

    public function test_a_division_locked_user_cannot_open_a_sibling_division_in_the_batch(): void
    {
        $pointeur = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'enterprise_id' => $this->a->id, 'can_access_pointage' => true]);

        $this->open([$this->a->id, $this->b->id], $pointeur)->assertForbidden();
        $this->assertSame(0, Quinzaine::count());
    }

    public function test_each_division_shows_its_progress_and_what_is_still_a_preciser(): void
    {
        $q = Quinzaine::create(['enterprise_id' => $this->a->id, 'label' => 'Q', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15', 'is_closed' => false]);
        $empty = Quinzaine::create(['enterprise_id' => $this->b->id, 'label' => 'Q', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15', 'is_closed' => false]);
        $op = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Irrigation', 'abbreviation' => 'Irr']);
        $bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        foreach (['001', '002'] as $m) {
            $worker = Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => $this->a->id, 'matricule' => $m, 'full_name' => "W{$m}", 'base_rate' => 97.44, 'is_active' => true]);
            PointageRecord::create(['employee_id' => $worker->id, 'quinzaine_id' => $q->id, 'date' => '2026-11-02', 'present' => true,
                'operation_id' => $m === '001' ? $op->id : null, 'bloc_id' => $m === '001' ? $bloc->id : null, 'hours' => 0, 'rate' => 97.44, 'brut' => 97.44, 'net' => 100]);
        }

        $this->actingAs($this->manager)->get(route('pointage.quinzaines'))->assertInertia(fn ($p) => $p
            ->where('quinzaines', fn ($list) => collect($list)->firstWhere('id', $q->id)['stats'] === ['ouvriers' => 2, 'jours' => 2, 'net' => 200, 'a_preciser' => 1]
                && collect($list)->firstWhere('id', $empty->id)['stats'] === ['ouvriers' => 0, 'jours' => 0, 'net' => 0, 'a_preciser' => 0]));
    }

    public function test_closing_with_days_a_preciser_is_refused_with_a_message(): void
    {
        $q = Quinzaine::create(['enterprise_id' => $this->a->id, 'label' => 'Q', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15', 'is_closed' => false]);
        $worker = Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => $this->a->id, 'matricule' => '001', 'full_name' => 'W', 'base_rate' => 97.44, 'is_active' => true]);
        PointageRecord::create(['employee_id' => $worker->id, 'quinzaine_id' => $q->id, 'date' => '2026-11-02', 'present' => true, 'hours' => 0, 'rate' => 97.44, 'brut' => 97.44, 'net' => 100]);

        $this->actingAs($this->manager)->from(route('pointage.quinzaines'))->post(route('settings.quinzaine.close', $q))
            ->assertSessionHasErrors('quinzaine');
        $this->assertFalse($q->fresh()->is_closed);
    }
}
