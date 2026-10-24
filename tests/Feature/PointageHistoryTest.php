<?php

namespace Tests\Feature;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageChange;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every change to a pointage day is traced: who, when, before and after. */
class PointageHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Quinzaine $q;

    private User $manager;

    private Employee $worker;

    private Operation $irr;

    private Operation $dm;

    private Bloc $bloc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $division = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'A', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $this->q = Quinzaine::create(['enterprise_id' => $division->id, 'label' => 'Q', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15', 'is_closed' => false]);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id, 'name' => 'Chef Bureau']);
        $this->worker = Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => $division->id, 'matricule' => '001', 'full_name' => 'W', 'base_rate' => 97.44, 'complement' => 9.13, 'is_active' => true]);
        $this->irr = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Irrigation', 'abbreviation' => 'Irr']);
        $this->dm = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Désherbage', 'abbreviation' => 'D.M']);
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
    }

    private function cell(array $data)
    {
        return $this->actingAs($this->manager)->post(route('pointage.cell'), $data + [
            'employee_id' => $this->worker->id, 'quinzaine_id' => $this->q->id, 'date' => '2026-11-02',
        ])->assertSessionHasNoErrors();
    }

    private function history()
    {
        return $this->actingAs($this->manager)->getJson(route('pointage.history', [
            'employee_id' => $this->worker->id, 'quinzaine_id' => $this->q->id, 'date' => '2026-11-02',
        ]));
    }

    public function test_creating_changing_and_clearing_a_day_is_traced_with_who_and_what(): void
    {
        $this->cell(['operation_id' => null, 'bloc_id' => null, 'present' => true]);
        $this->cell(['operation_id' => $this->dm->id, 'bloc_id' => $this->bloc->id, 'hours' => 2]);
        $this->cell(['operation_id' => null, 'bloc_id' => null]);

        $changes = PointageChange::orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $changes->pluck('action')->all());
        $this->assertSame($this->manager->id, $changes[0]->user_id);
        $this->assertEquals(['operation_id' => null, 'bloc_id' => null, 'hours' => 0, 'net' => 100], $changes[1]->avant);
        $this->assertSame($this->dm->id, $changes[1]->apres['operation_id']);
        $this->assertTrue($changes[2]->avant['present']);

        // In words, newest first.
        $this->history()->assertOk()
            ->assertJsonPath('0.action', 'Supprimé')
            ->assertJsonPath('1.action', 'Modifié')
            ->assertJsonPath('1.qui', 'Chef Bureau')
            ->assertJsonPath('1.lignes', ['Opération : — → D.M', 'Bloc : — → B1', 'H.S : 0 → 2', 'Net : 100,00 DH → 122,72 DH'])
            ->assertJsonPath('2.action', 'Saisi');
    }

    public function test_repartir_is_traced_and_a_change_of_nothing_that_matters_is_not(): void
    {
        $this->cell(['operation_id' => null, 'bloc_id' => null, 'present' => true]);
        $this->actingAs($this->manager)->post(route('pointage.repartition'), [
            'quinzaine_id' => $this->q->id, 'date' => '2026-11-02',
            'lignes' => [['bloc_id' => $this->bloc->id, 'operation_id' => $this->irr->id, 'effectif' => 1]],
        ])->assertSessionHasNoErrors();

        $last = PointageChange::latest('id')->first();
        $this->assertSame('updated', $last->action);
        // assertEquals: MySQL stores JSON with its keys sorted.
        $this->assertEquals(['operation_id' => $this->irr->id, 'bloc_id' => $this->bloc->id], $last->apres);

        PointageRecord::sole()->update(['scan_uuid' => 'abc']);
        $this->assertSame(2, PointageChange::count());
    }

    public function test_the_history_of_another_farm_is_forbidden(): void
    {
        $other = User::factory()->create(['role' => 'farm_manager', 'farm_id' => Farm::create(['name' => 'Other'])->id]);

        $this->actingAs($other)->getJson(route('pointage.history', [
            'employee_id' => $this->worker->id, 'quinzaine_id' => $this->q->id, 'date' => '2026-11-02',
        ]))->assertForbidden();
    }
}
