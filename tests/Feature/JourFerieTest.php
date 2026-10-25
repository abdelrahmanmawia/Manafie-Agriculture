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
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marking a jour férié for every open division at once (JourFerieService): who is paid follows
 * Code du travail art. 219 §2 — worked on the division's last work day before the holiday, or
 * 13 days in the 30 days before it.
 */
class JourFerieTest extends TestCase
{
    use RefreshDatabase;

    private const HOLIDAY = '2026-01-11';

    private Farm $farm;

    private Enterprise $avec;

    private Enterprise $sans;

    private Quinzaine $qAvec;

    private Quinzaine $qSans;

    private Quinzaine $previousAvec;

    private User $manager;

    private Operation $operation;

    private Operation $pieceOperation;

    private Bloc $bloc;

    private PayrollService $payroll;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payroll = app(PayrollService::class);
        $this->farm = Farm::create(['name' => 'Farm']);
        $this->avec = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'Avec', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 100]);
        $this->sans = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'Sans', 'contract_type' => 'sans_contrat', 'default_brut_rate' => 90]);
        $this->previousAvec = $this->quinzaine($this->avec, '2025-12-16', '2025-12-31');
        $this->qAvec = $this->quinzaine($this->avec, '2026-01-01', '2026-01-15');
        $this->qSans = $this->quinzaine($this->sans, '2026-01-01', '2026-01-15');
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->operation = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Taille']);
        $this->pieceOperation = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Cueillette', 'unit_rate' => 5]);
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
    }

    private function quinzaine(Enterprise $enterprise, string $start, string $end, bool $closed = false): Quinzaine
    {
        return Quinzaine::create(['enterprise_id' => $enterprise->id, 'label' => $start, 'start_date' => $start, 'end_date' => $end, 'is_closed' => $closed]);
    }

    private function employee(Enterprise $enterprise, string $name, float $complement = 0): Employee
    {
        return Employee::create([
            'farm_id' => $this->farm->id, 'enterprise_id' => $enterprise->id, 'matricule' => uniqid(),
            'full_name' => $name, 'base_rate' => 100, 'complement' => $complement, 'is_active' => true,
        ]);
    }

    private function worked(Employee $employee, Quinzaine $quinzaine, string $date, bool $pieceRate = false): PointageRecord
    {
        return PointageRecord::create([
            'employee_id' => $employee->id, 'quinzaine_id' => $quinzaine->id, 'date' => $date,
            'operation_id' => $pieceRate ? $this->pieceOperation->id : $this->operation->id, 'bloc_id' => $this->bloc->id,
            'hours' => 0, 'quantity' => $pieceRate ? 40 : null, 'is_jf' => false,
            'rate' => $pieceRate ? 5 : $quinzaine->enterprise->default_brut_rate, 'brut' => 100, 'net' => $pieceRate ? 200 : 93.26,
        ]);
    }

    private function daysBefore(Employee $employee, int $count, Quinzaine $quinzaine, string $lastDate): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->worked($employee, $quinzaine, now()->parse($lastDate)->subDays($i)->toDateString());
        }
    }

    private function mark(?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->post(route('pointage.jour-ferie.store'), ['quinzaine_id' => $this->qAvec->id, 'date' => self::HOLIDAY]);
    }

    private function recordOn(Employee $employee, string $date = self::HOLIDAY): ?PointageRecord
    {
        return PointageRecord::where('employee_id', $employee->id)->whereDate('date', $date)->first();
    }

    public function test_worker_who_worked_the_holiday_gets_it_marked_as_a_double_day(): void
    {
        $ali = $this->employee($this->avec, 'Ali', 10);
        $this->worked($ali, $this->qAvec, self::HOLIDAY);

        $this->mark()->assertSessionHasNoErrors();

        $record = $this->recordOn($ali);
        $this->assertTrue($record->is_jf);
        $expected = $this->payroll->calculate('avec_contrat', 100, 0, 10, true, null, true)['total_net'];
        $this->assertEqualsWithDelta($expected, $record->net, 0.01);
        $this->assertEqualsWithDelta(2 * (100 * 0.9326 + 10), $record->net, 0.01);
    }

    public function test_worker_present_on_the_last_work_day_gets_one_paid_day(): void
    {
        $sara = $this->employee($this->avec, 'Sara', 10);
        $this->worked($sara, $this->qAvec, '2026-01-10');

        $this->mark();

        $record = $this->recordOn($sara);
        $this->assertNotNull($record);
        $this->assertTrue($record->is_jf);
        $this->assertNull($record->operation_id);
        $this->assertEqualsWithDelta(100 * 0.9326 + 10, $record->net, 0.01);
    }

    public function test_last_work_day_is_the_divisions_not_the_calendar_day_before(): void
    {
        // Nobody worked on the 10th (a Saturday off, say): the division's last work day is the 9th.
        $omar = $this->employee($this->avec, 'Omar');
        $this->worked($omar, $this->qAvec, '2026-01-09');

        $this->mark();

        $this->assertTrue($this->recordOn($omar)->is_jf);
    }

    public function test_thirteen_days_in_the_previous_thirty_also_qualify(): void
    {
        $other = $this->employee($this->avec, 'Other');
        $this->worked($other, $this->qAvec, '2026-01-10'); // makes the 10th the last work day
        $hind = $this->employee($this->avec, 'Hind');
        $this->daysBefore($hind, 13, $this->previousAvec, '2025-12-31'); // Dec 19..31, not the 10th

        $this->mark();

        $this->assertTrue($this->recordOn($hind)->is_jf);
    }

    public function test_twelve_days_and_absent_on_the_last_work_day_is_not_eligible(): void
    {
        $other = $this->employee($this->avec, 'Other');
        $this->worked($other, $this->qAvec, '2026-01-10');
        $nadia = $this->employee($this->avec, 'Nadia');
        $this->daysBefore($nadia, 12, $this->previousAvec, '2025-12-31');

        $this->mark()->assertSessionHas('jourFerie', fn ($flash) => in_array('Nadia', $flash['divisions'][0]['not_eligible']));

        $this->assertNull($this->recordOn($nadia));
    }

    public function test_piece_rate_workers_are_left_for_manual_handling(): void
    {
        $onDay = $this->employee($this->avec, 'Piece On Day');
        $pieceRecord = $this->worked($onDay, $this->qAvec, self::HOLIDAY, true);
        $pieceOnly = $this->employee($this->avec, 'Piece Only');
        $this->worked($pieceOnly, $this->qAvec, '2026-01-10', true);

        $this->mark()->assertSessionHas('jourFerie', function ($flash) {
            $manual = $flash['divisions'][0]['manual'];

            return in_array('Piece On Day', $manual) && in_array('Piece Only', $manual);
        });

        $this->assertFalse($pieceRecord->fresh()->is_jf);
        $this->assertNull($this->recordOn($pieceOnly));
    }

    public function test_every_open_division_is_covered_sans_contrat_included(): void
    {
        $karim = $this->employee($this->sans, 'Karim');
        $this->worked($karim, $this->qSans, '2026-01-10');

        $this->mark();

        $record = $this->recordOn($karim);
        $this->assertTrue($record->is_jf);
        $this->assertEqualsWithDelta(90, $record->net, 0.01); // sans_contrat: one brut day
    }

    public function test_a_closed_division_is_left_untouched(): void
    {
        $this->qSans->update(['is_closed' => true]);
        $karim = $this->employee($this->sans, 'Karim');
        $this->worked($karim, $this->qSans, '2026-01-10');

        $this->mark();

        $this->assertNull($this->recordOn($karim));
    }

    public function test_a_division_locked_user_only_marks_their_own_division(): void
    {
        $pointeur = User::factory()->create([
            'role' => 'data_entry', 'farm_id' => $this->farm->id, 'enterprise_id' => $this->avec->id, 'acces_pointage' => 'saisie',
        ]);
        $karim = $this->employee($this->sans, 'Karim');
        $this->worked($karim, $this->qSans, '2026-01-10');
        $sara = $this->employee($this->avec, 'Sara');
        $this->worked($sara, $this->qAvec, '2026-01-10');

        $this->mark($pointeur)->assertSessionHasNoErrors();

        $this->assertNotNull($this->recordOn($sara));
        $this->assertNull($this->recordOn($karim));
    }

    public function test_marking_twice_creates_nothing_new(): void
    {
        $sara = $this->employee($this->avec, 'Sara');
        $this->worked($sara, $this->qAvec, '2026-01-10');

        $this->mark();
        $this->mark()->assertSessionHas('jourFerie', fn ($flash) => $flash['divisions'][0]['already'] === 1 && $flash['divisions'][0]['paid'] === 0);

        $this->assertSame(1, PointageRecord::where('employee_id', $sara->id)->whereDate('date', self::HOLIDAY)->count());
    }

    public function test_unmarking_reverses_the_holiday(): void
    {
        $ali = $this->employee($this->avec, 'Ali', 10);
        $this->worked($ali, $this->qAvec, self::HOLIDAY);
        $sara = $this->employee($this->avec, 'Sara');
        $this->worked($sara, $this->qAvec, '2026-01-10');
        $piece = $this->employee($this->avec, 'Piece');
        $pieceRecord = $this->worked($piece, $this->qAvec, self::HOLIDAY, true);
        $this->mark();

        $this->actingAs($this->manager)->delete(route('pointage.jour-ferie.destroy'), ['quinzaine_id' => $this->qAvec->id, 'date' => self::HOLIDAY])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->recordOn($sara));
        $aliRecord = $this->recordOn($ali);
        $this->assertFalse($aliRecord->is_jf);
        $this->assertEqualsWithDelta(100 * 0.9326 + 10, $aliRecord->net, 0.01);
        $this->assertSame(200.0, (float) $pieceRecord->fresh()->net);
    }

    public function test_requests_are_refused_on_a_closed_period_or_outside_it(): void
    {
        $this->actingAs($this->manager)->post(route('pointage.jour-ferie.store'), ['quinzaine_id' => $this->qAvec->id, 'date' => '2026-02-01'])
            ->assertSessionHasErrors('date');

        $this->qAvec->update(['is_closed' => true]);
        $this->mark()->assertSessionHasErrors('date');
    }

    public function test_another_farms_manager_is_refused(): void
    {
        $otherFarm = Farm::create(['name' => 'Other']);
        $otherManager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $otherFarm->id]);

        $this->mark($otherManager)->assertForbidden();
    }
}
