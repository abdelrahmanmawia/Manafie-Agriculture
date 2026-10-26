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
use App\Models\TransportCompany;
use App\Models\TransportVehicle;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\TransportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two pointage steps: present per équipe (operation "à préciser"), then operations per
 * division — worker by worker or by headcount.
 */
class PresenceRepartitionTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $divA;

    private Enterprise $divB;

    private Quinzaine $qa;

    private Quinzaine $qb;

    private User $manager;

    private Operation $irr;

    private Operation $dm;

    private Bloc $b1;

    private Bloc $b2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $mk = fn ($name) => Enterprise::create([
            'farm_id' => $this->farm->id, 'name' => $name, 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $this->divA = $mk('A');
        $this->divB = $mk('B');
        $this->qa = Quinzaine::create(['enterprise_id' => $this->divA->id, 'label' => 'Q', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'is_closed' => false]);
        $this->qb = Quinzaine::create(['enterprise_id' => $this->divB->id, 'label' => 'Q', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'is_closed' => false]);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->irr = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Irrigation', 'abbreviation' => 'Irr']);
        $this->dm = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Desherbage manuel', 'abbreviation' => 'D.M']);
        $this->b1 = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        $this->b2 = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B2']);
    }

    private function worker(string $name, Enterprise $division, ?Equipe $equipe = null, float $complement = 0): Employee
    {
        return Employee::create([
            'farm_id' => $this->farm->id, 'enterprise_id' => $division->id, 'matricule' => $name, 'full_name' => $name,
            'base_rate' => 97.44, 'complement' => $complement, 'is_active' => true, 'equipe_id' => $equipe?->id,
        ]);
    }

    private function cell(Employee $employee, string $date, array $data)
    {
        return $this->actingAs($this->manager)->post('/pointage/cell', $data + [
            'employee_id' => $employee->id,
            'quinzaine_id' => $employee->enterprise_id === $this->divA->id ? $this->qa->id : $this->qb->id,
            'date' => $date,
        ]);
    }

    private function present(array $employees, string $date = '2026-10-05')
    {
        return $this->actingAs($this->manager)->post(route('pointage.presence'), [
            'quinzaine_id' => $this->qa->id, 'date' => $date, 'employee_ids' => array_map(fn ($e) => $e->id, $employees),
        ]);
    }

    private function repartir(array $lignes, string $date = '2026-10-05')
    {
        return $this->actingAs($this->manager)->post(route('pointage.repartition'), [
            'quinzaine_id' => $this->qa->id, 'date' => $date,
            'lignes' => array_map(fn ($l) => ['bloc_id' => $l[0]->id, 'operation_id' => $l[1]->id, 'effectif' => $l[2]], $lignes),
        ]);
    }

    private function record(Employee $employee, string $date = '2026-10-05'): PointageRecord
    {
        return PointageRecord::where('employee_id', $employee->id)->whereDate('date', $date)->sole();
    }

    public function test_present_without_operation_is_paid_as_a_worked_day(): void
    {
        $worker = $this->worker('A', $this->divA, null, 18.13);
        $this->cell($worker, '2026-10-05', ['present' => true, 'hours' => 2])->assertSessionHasNoErrors();

        $record = $this->record($worker);
        $expected = app(PayrollService::class)->calculate('avec_contrat', 97.44, 2, 18.13);
        $this->assertTrue($record->present);
        $this->assertNull($record->operation_id);
        $this->assertEqualsWithDelta($expected['total_net'], $record->net, 0.01);
        // It's a worked day for invoicing too.
        $this->assertSame(1, app(PayrollService::class)->invoicingForRecords('avec_contrat', collect([$record]), 18.13, true)['days']);
    }

    public function test_an_unworked_holiday_is_still_not_present(): void
    {
        $worker = $this->worker('A', $this->divA);
        $this->cell($worker, '2026-10-05', ['is_jf' => true])->assertSessionHasNoErrors();

        $this->assertFalse($this->record($worker)->present);
    }

    public function test_tous_presents_marks_the_team_in_their_own_divisions_and_counts_for_transport(): void
    {
        $company = TransportCompany::create(['farm_id' => $this->farm->id, 'name' => 'Co']);
        $bus = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $company->id, 'code' => 'T1', 'price_per_person' => 30]);
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'E1', 'transport_vehicle_id' => $bus->id]);
        $a = $this->worker('A', $this->divA, $equipe);
        $b = $this->worker('B', $this->divB, $equipe);
        $already = $this->worker('C', $this->divA, $equipe);
        $this->cell($already, '2026-10-05', ['operation_id' => $this->dm->id, 'bloc_id' => $this->b1->id]);

        $this->present([$a, $b, $already])->assertSessionHasNoErrors()->assertSessionHas('success', '2 ouvrier(s) marqué(s) présent(s).');

        $this->assertSame($this->qa->id, $this->record($a)->quinzaine_id);
        $this->assertSame($this->qb->id, $this->record($b)->quinzaine_id);
        $this->assertSame($this->dm->id, $this->record($already)->operation_id);
        $preview = app(TransportService::class)->previewForDateRange(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-15'), $this->farm->id);
        $this->assertSame(3, $preview[0]['rider_days']);
    }

    public function test_tous_presents_skips_closed_divisions_and_other_divisions_for_a_locked_user(): void
    {
        $a = $this->worker('A', $this->divA);
        $b = $this->worker('B', $this->divB);
        $locked = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'enterprise_id' => $this->divA->id]);

        $this->actingAs($locked)->post(route('pointage.presence'), [
            'quinzaine_id' => $this->qa->id, 'date' => '2026-10-05', 'employee_ids' => [$a->id, $b->id],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, PointageRecord::count());

        $this->qb->update(['is_closed' => true]);
        $this->present([$b], '2026-10-06');
        $this->assertSame(0, PointageRecord::where('employee_id', $b->id)->count());
    }

    public function test_repartition_keeps_yesterdays_operation_then_fills_by_name(): void
    {
        [$ali, $badr, $chama, $driss] = array_map(fn ($n) => $this->worker($n, $this->divA), ['Ali', 'Badr', 'Chama', 'Driss']);
        // The day before: Driss irrigated, the others weeded.
        $this->cell($driss, '2026-10-04', ['operation_id' => $this->irr->id, 'bloc_id' => $this->b1->id]);
        $this->cell($ali, '2026-10-04', ['operation_id' => $this->dm->id, 'bloc_id' => $this->b2->id]);

        $this->present([$ali, $badr, $chama, $driss]);
        $this->repartir([[$this->b1, $this->irr, 1], [$this->b2, $this->dm, 2]])
            ->assertSessionHasNoErrors()->assertSessionHas('success', '3 ouvrier(s) affecté(s), 1 encore à préciser.');

        $this->assertSame($this->irr->id, $this->record($driss)->operation_id);
        $this->assertSame($this->dm->id, $this->record($ali)->operation_id);
        // The one free D.M place goes by name: Badr before Chama.
        $this->assertSame($this->dm->id, $this->record($badr)->operation_id);
        $this->assertNull($this->record($chama)->operation_id);
        $this->assertTrue($this->record($chama)->present);
    }

    public function test_repartition_counts_workers_already_on_an_operation(): void
    {
        $ali = $this->worker('Ali', $this->divA);
        $badr = $this->worker('Badr', $this->divA);
        $this->cell($ali, '2026-10-05', ['operation_id' => $this->irr->id, 'bloc_id' => $this->b1->id]);
        $this->present([$badr]);

        $this->repartir([[$this->b1, $this->irr, 2]])->assertSessionHasNoErrors();

        $this->assertSame($this->irr->id, $this->record($badr)->operation_id);
        $this->assertSame(2, PointageRecord::where('operation_id', $this->irr->id)->count());
    }

    public function test_repartition_refuses_more_workers_than_were_present(): void
    {
        $ali = $this->worker('Ali', $this->divA);
        $this->present([$ali]);

        $this->repartir([[$this->b1, $this->irr, 2]])->assertSessionHasErrors('repartition');

        $this->assertNull($this->record($ali)->operation_id);
    }

    public function test_repartition_refuses_a_piece_rate_operation(): void
    {
        $piece = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Brise vent', 'abbreviation' => 'F.B.V', 'unit_rate' => 10]);
        $this->present([$this->worker('Ali', $this->divA)]);

        $this->repartir([[$this->b1, $piece, 1]])->assertSessionHasErrors('repartition');
    }

    public function test_a_period_with_days_a_preciser_cannot_close(): void
    {
        $ali = $this->worker('Ali', $this->divA);
        $this->present([$ali]);

        $this->actingAs($this->manager)->post('/settings/quinzaine/'.$this->qa->id.'/close')->assertSessionHasErrors('quinzaine');
        $this->assertFalse($this->qa->fresh()->is_closed);

        $this->repartir([[$this->b1, $this->irr, 1]]);
        $this->actingAs($this->manager)->post('/settings/quinzaine/'.$this->qa->id.'/close')->assertSessionHasNoErrors();
        $this->assertTrue($this->qa->fresh()->is_closed);
    }

    public function test_giving_the_operation_later_keeps_the_pay(): void
    {
        $ali = $this->worker('Ali', $this->divA, null, 10);
        $this->cell($ali, '2026-10-05', ['present' => true, 'hours' => 1]);
        $net = $this->record($ali)->net;

        $this->cell($ali, '2026-10-05', ['operation_id' => $this->irr->id, 'bloc_id' => $this->b1->id, 'hours' => 1]);

        $this->assertEqualsWithDelta($net, $this->record($ali)->net, 0.001);
        $this->assertTrue($this->record($ali)->present);
    }

    public function test_an_equipes_day_is_split_across_its_workers_divisions(): void
    {
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'T1']);
        $ali = $this->worker('Ali', $this->divA, $equipe);
        $badr = $this->worker('Badr', $this->divB, $equipe);
        $chama = $this->worker('Chama', $this->divB, $equipe);
        $horsEquipe = $this->worker('Driss', $this->divA);
        $this->present([$ali, $badr, $chama, $horsEquipe]);

        $this->actingAs($this->manager)->post(route('pointage.repartition'), [
            'quinzaine_id' => $this->qa->id, 'date' => '2026-10-05',
            'employee_ids' => [$ali->id, $badr->id, $chama->id],
            'lignes' => [['bloc_id' => $this->b1->id, 'operation_id' => $this->irr->id, 'effectif' => 3]],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', '3 ouvrier(s) affecté(s).');

        // Each day stays in its own division's quinzaine; the worker outside the équipe is untouched.
        foreach ([$ali, $badr, $chama] as $worker) {
            $this->assertSame($this->irr->id, $this->record($worker)->operation_id);
        }
        $this->assertSame($this->qb->id, $this->record($badr)->quinzaine_id);
        $this->assertNull($this->record($horsEquipe)->operation_id);
    }

    public function test_an_equipes_split_leaves_closed_divisions_alone_and_counts_only_its_open_days(): void
    {
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'T1']);
        $ali = $this->worker('Ali', $this->divA, $equipe);
        $badr = $this->worker('Badr', $this->divB, $equipe);
        $this->present([$ali, $badr]);
        $this->qb->update(['is_closed' => true]);

        $repartir = fn (int $effectif) => $this->actingAs($this->manager)->post(route('pointage.repartition'), [
            'quinzaine_id' => $this->qa->id, 'date' => '2026-10-05', 'employee_ids' => [$ali->id, $badr->id],
            'lignes' => [['bloc_id' => $this->b1->id, 'operation_id' => $this->irr->id, 'effectif' => $effectif]],
        ]);
        $repartir(2)->assertSessionHasErrors('repartition'); // only Ali's day is still open
        $repartir(1)->assertSessionHasNoErrors();

        $this->assertSame($this->irr->id, $this->record($ali)->operation_id);
        $this->assertNull($this->record($badr)->operation_id);
    }
}
