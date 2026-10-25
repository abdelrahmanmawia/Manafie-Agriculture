<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Divisions page, the payroll history's rows and filters, the duplicate-worker warning. */
class UxCleanupTest extends TestCase
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

    private function worker(string $name, ?string $cin = null, ?Enterprise $division = null): Employee
    {
        static $n = 0;

        return Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => ($division ?? $this->a)->id, 'matricule' => (string) ++$n,
            'full_name' => $name, 'cin' => $cin, 'base_rate' => 97.44, 'is_active' => true]);
    }

    private function add(string $name, ?string $cin, bool $confirm = false)
    {
        return $this->actingAs($this->manager)->post(route('employees.store'), array_filter([
            'full_name' => $name, 'cin' => $cin, 'base_rate' => 97.44, 'enterprise_id' => $this->a->id, 'confirmer_doublon' => $confirm ?: null,
        ]));
    }

    public function test_the_divisions_page_lists_the_divisions_without_the_old_dashboard_totals(): void
    {
        $this->actingAs($this->manager)->get(route('pointage.index'))->assertInertia(fn ($p) => $p
            ->component('Pointage/Index')->has('enterprises', 2)->missing('totals'));
    }

    public function test_a_pointage_only_data_entry_lands_on_aujourdhui(): void
    {
        $pointeur = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'acces_pointage' => 'saisie', 'acces_stock' => 'aucun']);

        $this->actingAs($pointeur)->get(route('dashboard'))->assertRedirect(route('pointage.today'));
    }

    public function test_the_payroll_history_of_a_division_keeps_a_worker_who_moved_away_and_gets_the_equipes(): void
    {
        $q = Quinzaine::create(['enterprise_id' => $this->a->id, 'label' => 'Q', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15', 'is_closed' => false]);
        $moved = $this->worker('MOVED');
        PointageRecord::create(['employee_id' => $moved->id, 'quinzaine_id' => $q->id, 'date' => '2026-11-02', 'present' => true, 'hours' => 0, 'rate' => 97.44, 'brut' => 97.44, 'net' => 100]);
        $moved->update(['enterprise_id' => $this->b->id]);
        $stays = $this->worker('STAYS');
        Equipe::create(['farm_id' => $this->farm->id, 'name' => 'ONE']);

        $this->actingAs($this->manager)->get(route('payroll.history', ['enterprise_id' => $this->a->id]))->assertInertia(fn ($p) => $p
            ->where('employees', fn ($list) => collect($list)->pluck('full_name')->sort()->values()->all() === ['MOVED', 'STAYS'])
            ->where("history.{$moved->id}.0.total_net", 100)
            ->where('equipes.0.name', 'ONE'));
    }

    public function test_adding_a_worker_who_looks_like_an_existing_one_asks_for_confirmation(): void
    {
        $this->worker('KORCHI HADDOUM', 'G535675');

        // A name a letter away, or a CIN a typo away: warned, not created.
        $this->add('KORCHI HADOUM', null)->assertSessionHasErrors('doublon');
        $this->add('BENANI SARA', 'G35675')->assertSessionHasErrors('doublon');
        $this->assertSame(1, Employee::count());

        // Confirmed: created. A clearly different person: created without asking.
        $this->add('KORCHI HADOUM', null, true)->assertSessionHasNoErrors();
        $this->add('ALAOUI MERYEM', 'X999999')->assertSessionHasNoErrors();
        $this->assertSame(3, Employee::count());
    }

    public function test_the_warning_names_the_lookalike(): void
    {
        $mina = $this->worker('EL KORCHI MINA', 'G537718', $this->b);

        $this->add('KORCHI MINA', null)->assertSessionHasErrors(['doublon' => "Ressemble à : EL KORCHI MINA (mat. {$mina->matricule}, CIN G537718, B). Si c'est bien une autre personne, cliquez « Créer quand même »."]);
    }
}
