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
use App\Models\TransportSnapshot;
use App\Models\TransportVehicle;
use App\Models\TransportVehicleAbsence;
use App\Models\User;
use App\Services\TransportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transport cost comes from the pointage: per day, the vehicle's riders who worked × its price
 * per person (or its flat daily rate), 0 when nobody worked or when it's marked absent.
 */
class TransportCostTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $divA;

    private Enterprise $divB;

    private Quinzaine $qa;

    private Quinzaine $qb;

    private User $manager;

    private TransportVehicle $bus;

    private Equipe $equipe;

    private Operation $operation;

    private Bloc $bloc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $mk = fn ($name) => Enterprise::create([
            'farm_id' => $this->farm->id, 'name' => $name, 'contract_type' => 'avec_contrat', 'default_brut_rate' => 100,
        ]);
        $this->divA = $mk('A');
        $this->divB = $mk('B');
        $this->qa = Quinzaine::create(['enterprise_id' => $this->divA->id, 'label' => 'Q', 'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false]);
        $this->qb = Quinzaine::create(['enterprise_id' => $this->divB->id, 'label' => 'Q', 'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false]);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $company = TransportCompany::create(['farm_id' => $this->farm->id, 'name' => 'Transco']);
        $this->bus = TransportVehicle::create([
            'farm_id' => $this->farm->id, 'transport_company_id' => $company->id, 'code' => 'T1', 'price_per_person' => 30,
        ]);
        $this->equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'Equipe 1', 'transport_vehicle_id' => $this->bus->id]);
        $this->operation = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Taille']);
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
    }

    private function worker(string $name, Enterprise $division, ?Equipe $equipe = null): Employee
    {
        return Employee::create([
            'farm_id' => $this->farm->id, 'enterprise_id' => $division->id, 'matricule' => $name, 'full_name' => $name,
            'base_rate' => 100, 'is_active' => true, 'equipe_id' => ($equipe ?? $this->equipe)->id,
        ]);
    }

    /** Enters a worked day through the Grid, as a user would. */
    private function work(Employee $employee, string $date, array $overrides = [])
    {
        return $this->actingAs($this->manager)->post('/pointage/cell', array_merge([
            'employee_id' => $employee->id,
            'quinzaine_id' => $employee->enterprise_id === $this->divA->id ? $this->qa->id : $this->qb->id,
            'operation_id' => $this->operation->id,
            'bloc_id' => $this->bloc->id,
            'date' => $date,
        ], $overrides))->assertSessionHasNoErrors();
    }

    private function preview(): array
    {
        return app(TransportService::class)->previewForDateRange(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-15'), $this->farm->id);
    }

    public function test_a_day_costs_the_riders_who_worked_times_the_price_per_person_across_divisions(): void
    {
        $a = $this->worker('A', $this->divA);
        $b = $this->worker('B', $this->divB);
        $this->worker('Stayed home', $this->divA);
        $this->work($a, '2026-01-05');
        $this->work($b, '2026-01-05');
        $this->work($a, '2026-01-06');

        $rows = $this->preview();

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['days_count']);
        $this->assertSame(3, $rows[0]['rider_days']);
        $this->assertEquals(90, $rows[0]['subtotal']);
    }

    public function test_a_day_nobody_worked_costs_nothing(): void
    {
        $this->worker('A', $this->divA);

        $this->assertSame([], $this->preview());
        $this->assertSame([], app(TransportService::class)->daily(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-15'), $this->farm->id)['days']);
    }

    public function test_an_unworked_jour_ferie_is_not_a_ride_but_a_worked_one_is(): void
    {
        $a = $this->worker('A', $this->divA);
        $b = $this->worker('B', $this->divA);
        // Unworked JF: a paid record with no operation — the worker didn't come.
        PointageRecord::create([
            'employee_id' => $a->id, 'quinzaine_id' => $this->qa->id, 'date' => '2026-01-11', 'is_jf' => true, 'present' => false,
            'transport_vehicle_id' => $this->bus->id, 'hours' => 0, 'rate' => 100, 'brut' => 100, 'net' => 90,
        ]);
        $this->work($b, '2026-01-11', ['is_jf' => true]);

        $rows = $this->preview();

        $this->assertSame(1, $rows[0]['rider_days']);
        $this->assertEquals(30, $rows[0]['subtotal']);
    }

    public function test_an_absent_vehicle_costs_nothing_but_its_riders_stay_paid(): void
    {
        $a = $this->worker('A', $this->divA);
        $this->work($a, '2026-01-05');
        $this->work($a, '2026-01-06');

        $this->actingAs($this->manager)->post(route('transport.absence.set'), [
            'transport_vehicle_id' => $this->bus->id, 'quinzaine_id' => $this->qa->id, 'date' => '2026-01-06', 'absent' => true,
        ])->assertSessionHasNoErrors();

        $rows = $this->preview();
        $this->assertSame(1, $rows[0]['days_count']);
        $this->assertEquals(30, $rows[0]['subtotal']);
        $this->assertSame(2, PointageRecord::where('employee_id', $a->id)->where('net', '>', 0)->count());

        $this->actingAs($this->manager)->post(route('transport.absence.set'), [
            'transport_vehicle_id' => $this->bus->id, 'quinzaine_id' => $this->qa->id, 'date' => '2026-01-06', 'absent' => false,
        ]);
        $this->assertEquals(60, $this->preview()[0]['subtotal']);
    }

    public function test_the_flat_daily_rate_replaces_the_per_person_price(): void
    {
        $this->bus->update(['fixed_net_per_day' => 500]);
        $a = $this->worker('A', $this->divA);
        $b = $this->worker('B', $this->divA);
        $this->work($a, '2026-01-05');
        $this->work($b, '2026-01-05');
        $this->work($a, '2026-01-06');

        $this->assertEquals(1000, $this->preview()[0]['subtotal']);
    }

    public function test_a_worker_moved_to_another_equipe_keeps_past_days_on_the_old_vehicle(): void
    {
        $other = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $this->bus->transport_company_id, 'code' => 'T2', 'price_per_person' => 50]);
        $otherEquipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'Equipe 2', 'transport_vehicle_id' => $other->id]);
        $a = $this->worker('A', $this->divA);
        $this->work($a, '2026-01-05');

        $a->update(['equipe_id' => $otherEquipe->id]);
        $this->work($a, '2026-01-06');
        // Editing the earlier day again doesn't move it to the new vehicle either.
        $this->work($a, '2026-01-05', ['hours' => 1]);

        $byVehicle = collect($this->preview())->keyBy('transport_vehicle_id');
        $this->assertEquals(30, $byVehicle[$this->bus->id]['subtotal']);
        $this->assertEquals(50, $byVehicle[$other->id]['subtotal']);
    }

    public function test_joining_an_equipe_puts_open_days_without_a_vehicle_on_its_vehicle(): void
    {
        $sansVehicule = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'Hommes']);
        $autreBus = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $this->bus->transport_company_id, 'code' => 'T2', 'price_per_person' => 50]);
        $autreEquipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'Equipe 2', 'transport_vehicle_id' => $autreBus->id]);

        $arrive = $this->worker('ARRIVE', $this->divA, $sansVehicule);
        $this->work($arrive, '2026-01-05');
        $this->work($arrive, '2026-01-06', ['operation_id' => null, 'bloc_id' => null, 'present' => true]);
        // A closed period stays as it was paid and costed.
        $fermee = Quinzaine::create(['enterprise_id' => $this->divA->id, 'label' => 'D', 'start_date' => '2025-12-16', 'end_date' => '2025-12-31', 'is_closed' => true]);
        $ancien = PointageRecord::create(['employee_id' => $arrive->id, 'quinzaine_id' => $fermee->id, 'date' => '2025-12-20', 'present' => true, 'hours' => 0, 'rate' => 100, 'brut' => 100, 'net' => 100]);
        // Not a day: an unworked J.F. has no rider.
        $jf = PointageRecord::create(['employee_id' => $arrive->id, 'quinzaine_id' => $this->qa->id, 'date' => '2026-01-07', 'present' => false, 'is_jf' => true, 'hours' => 0, 'rate' => 100, 'brut' => 100, 'net' => 100]);

        // Already on another vehicle: stays on it, the vehicle really came for them that day.
        $change = $this->worker('CHANGE', $this->divA, $autreEquipe);
        $this->work($change, '2026-01-05');

        // Already a member, a day from when the équipe was inactive: not an arrival, left alone.
        $membre = $this->worker('MEMBRE', $this->divA);
        $this->work($membre, '2026-01-05');
        $jourMembre = PointageRecord::where('employee_id', $membre->id)->sole();
        $jourMembre->update(['transport_vehicle_id' => null]);

        $this->actingAs($this->manager)->put(route('equipes.members', $this->equipe), ['employee_ids' => [$arrive->id, $change->id, $membre->id]])
            ->assertSessionHas('success', "Ouvriers de l'équipe mis à jour. 2 jour(s) de la période ouverte rattaché(s) à son transport.");

        $vehicule = fn ($date) => PointageRecord::where('employee_id', $arrive->id)->whereDate('date', $date)->sole()->transport_vehicle_id;
        $this->assertSame($this->bus->id, $vehicule('2026-01-05'));
        $this->assertSame($this->bus->id, $vehicule('2026-01-06'));
        $this->assertNull($ancien->fresh()->transport_vehicle_id);
        $this->assertNull($jf->fresh()->transport_vehicle_id);
        $this->assertSame($autreBus->id, PointageRecord::where('employee_id', $change->id)->sole()->transport_vehicle_id);
        $this->assertNull($jourMembre->fresh()->transport_vehicle_id);

        // The Grille Transport counts them: 2 days at 30 on the bus, 1 at 50 on the other.
        $byVehicle = collect($this->preview())->keyBy('transport_vehicle_id');
        $this->assertEquals(60, $byVehicle[$this->bus->id]['subtotal']);
        $this->assertEquals(50, $byVehicle[$autreBus->id]['subtotal']);
    }

    public function test_joining_an_equipe_without_a_vehicle_changes_no_day(): void
    {
        $sansVehicule = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'Hommes']);
        $worker = $this->worker('A', $this->divA, Equipe::create(['farm_id' => $this->farm->id, 'name' => 'Sans bus']));
        $this->work($worker, '2026-01-05');

        $this->actingAs($this->manager)->put(route('equipes.members', $sansVehicule), ['employee_ids' => [$worker->id]])
            ->assertSessionHas('success', "Ouvriers de l'équipe mis à jour.");

        $this->assertNull(PointageRecord::where('employee_id', $worker->id)->sole()->transport_vehicle_id);
    }

    public function test_a_chef_joining_their_equipe_rides_its_vehicle_on_open_days(): void
    {
        $chef = $this->worker('CHEF', $this->divA, Equipe::create(['farm_id' => $this->farm->id, 'name' => 'Hommes']));
        $this->work($chef, '2026-01-05');

        $this->actingAs($this->manager)->put(route('equipes.update', $this->equipe), ['chef_employee_id' => $chef->id])->assertSessionHasNoErrors();

        $this->assertSame($this->bus->id, PointageRecord::where('employee_id', $chef->id)->sole()->transport_vehicle_id);
    }

    public function test_a_worker_without_a_vehicle_is_nobodys_rider(): void
    {
        $walkers = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'Walkers']);
        $this->work($this->worker('A', $this->divA, $walkers), '2026-01-05');

        $this->assertSame([], $this->preview());
        $this->assertNull(PointageRecord::sole()->transport_vehicle_id);
    }

    public function test_closing_freezes_the_period_cost(): void
    {
        $a = $this->worker('A', $this->divA);
        $this->work($a, '2026-01-05');

        $this->actingAs($this->manager)->post('/settings/quinzaine/'.$this->qa->id.'/close')->assertRedirect();

        $snapshot = TransportSnapshot::sole();
        $this->assertEquals(30, $snapshot->price_per_person);
        $this->assertNull($snapshot->net_per_day);
        $this->assertSame(1, $snapshot->rider_days);
        $this->assertEquals(30, $snapshot->subtotal);

        // A later price change doesn't touch the frozen period.
        $this->bus->update(['price_per_person' => 99]);
        $this->actingAs($this->manager)->get(route('transport.summary.index'))
            ->assertInertia(fn ($p) => $p->where('isClosed', false)->where('breakdown.0.subtotal', 99));
        $this->assertEquals(30, TransportSnapshot::sole()->subtotal);
    }

    public function test_the_grid_shows_each_days_riders_and_cost(): void
    {
        $a = $this->worker('A', $this->divA);
        $this->work($a, '2026-01-05');
        TransportVehicleAbsence::create(['transport_vehicle_id' => $this->bus->id, 'date' => '2026-01-07']);

        $this->actingAs($this->manager)->get(route('transport.grid', $this->qa->id))
            ->assertInertia(fn ($p) => $p
                ->where('vehicles.0.price_per_person', 30)
                ->where("cells.{$this->bus->id}.2026-01-05.riders", 1)
                ->where("cells.{$this->bus->id}.2026-01-05.cost", 30)
                ->where("cells.{$this->bus->id}.2026-01-07.absent", true));
    }

    public function test_another_farms_vehicle_cannot_be_marked_absent(): void
    {
        $otherFarm = Farm::create(['name' => 'Other']);
        $foreign = TransportVehicle::create([
            'farm_id' => $otherFarm->id, 'transport_company_id' => TransportCompany::create(['farm_id' => $otherFarm->id, 'name' => 'X'])->id, 'code' => 'X1',
        ]);

        $this->actingAs($this->manager)->post(route('transport.absence.set'), [
            'transport_vehicle_id' => $foreign->id, 'quinzaine_id' => $this->qa->id, 'date' => '2026-01-05', 'absent' => true,
        ])->assertForbidden();

        $this->assertSame(0, TransportVehicleAbsence::count());
    }
}
