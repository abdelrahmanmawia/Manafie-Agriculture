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

/** A fixed monthly salary is paid once, in the month's second quinzaine; the days pay nothing. */
class SalaireFixeTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $division;

    private Quinzaine $first;

    private Quinzaine $second;

    private User $manager;

    private Operation $op;

    private Bloc $bloc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->division = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'ND', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $this->first = Quinzaine::create(['enterprise_id' => $this->division->id, 'label' => 'Q1', 'start_date' => '2026-09-01', 'end_date' => '2026-09-15', 'is_closed' => false]);
        $this->second = Quinzaine::create(['enterprise_id' => $this->division->id, 'label' => 'Q2', 'start_date' => '2026-09-16', 'end_date' => '2026-09-30', 'is_closed' => false]);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->op = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Pointeur', 'abbreviation' => 'POINTEUR']);
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
    }

    private function worker(): Employee
    {
        return Employee::create([
            'farm_id' => $this->farm->id, 'enterprise_id' => $this->division->id, 'matricule' => '001', 'full_name' => 'MOAWIA A',
            'base_rate' => 97.44, 'complement' => 43.75, 'is_active' => true,
        ]);
    }

    private function cell(Employee $employee, Quinzaine $quinzaine, string $date, array $data = [])
    {
        return $this->actingAs($this->manager)->post('/pointage/cell', $data + [
            'employee_id' => $employee->id, 'quinzaine_id' => $quinzaine->id, 'date' => $date,
            'operation_id' => $this->op->id, 'bloc_id' => $this->bloc->id,
        ])->assertSessionHasNoErrors();
    }

    private function setSalary(Employee $employee, ?float $salary)
    {
        return $this->actingAs($this->manager)->put(route('employees.update', $employee), [
            'full_name' => $employee->full_name, 'base_rate' => 97.44, 'complement' => 43.75,
            'enterprise_id' => $employee->enterprise_id, 'salaire_mensuel' => $salary,
        ])->assertSessionHasNoErrors();
    }

    private function salaryRecords(Employee $employee)
    {
        return PointageRecord::where('employee_id', $employee->id)->where('nature', PointageRecord::NATURE_SALAIRE)->get();
    }

    public function test_the_salary_is_paid_once_in_the_second_quinzaine_and_the_days_pay_nothing(): void
    {
        $worker = $this->worker();
        $this->cell($worker, $this->first, '2026-09-02');
        $this->cell($worker, $this->second, '2026-09-30');

        $this->setSalary($worker, 3500);

        $salary = $this->salaryRecords($worker)->sole();
        $this->assertSame($this->second->id, $salary->quinzaine_id);
        $this->assertEquals(3500, $salary->net);
        $this->assertSame('2026-09-30', $salary->date->toDateString());
        $this->assertEquals(0, PointageRecord::where('employee_id', $worker->id)->where('nature', 'jour')->sum('net'));
        $this->assertEquals(3500, PointageRecord::where('employee_id', $worker->id)->sum('net'));
    }

    public function test_a_salaried_worker_with_no_days_is_still_paid(): void
    {
        $worker = $this->worker();

        $this->setSalary($worker, 4000);

        $this->assertEquals(4000, $this->salaryRecords($worker)->sole()->net);
    }

    public function test_writing_or_clearing_the_last_day_leaves_the_salary_alone(): void
    {
        $worker = $this->worker();
        $this->setSalary($worker, 3500);

        $this->cell($worker, $this->second, '2026-09-30', ['hours' => 2]);
        $this->assertEquals(0, PointageRecord::where('employee_id', $worker->id)->where('nature', 'jour')->sole()->net);
        $this->cell($worker, $this->second, '2026-09-30', ['operation_id' => null, 'bloc_id' => null]);

        $this->assertSame(0, PointageRecord::where('employee_id', $worker->id)->where('nature', 'jour')->count());
        $this->assertEquals(3500, $this->salaryRecords($worker)->sole()->net);
    }

    public function test_removing_the_salary_restores_the_day_pay(): void
    {
        $worker = $this->worker();
        $this->setSalary($worker, 3500);
        $this->cell($worker, $this->second, '2026-09-20');

        $this->setSalary($worker, null);

        $this->assertCount(0, $this->salaryRecords($worker));
        $expected = app(PayrollService::class)->calculate('avec_contrat', 97.44, 0, 43.75)['total_net'];
        $this->assertEqualsWithDelta($expected, PointageRecord::where('employee_id', $worker->id)->sole()->net, 0.01);
    }

    public function test_a_closed_quinzaine_keeps_what_it_paid_and_a_new_second_quinzaine_pays_the_salary(): void
    {
        $worker = $this->worker();
        $this->second->update(['is_closed' => true]);

        $this->setSalary($worker, 3500);
        $this->assertCount(0, $this->salaryRecords($worker));

        $this->actingAs($this->manager)->post(route('settings.quinzaine'), [
            'enterprise_id' => $this->division->id, 'label' => 'Q2 oct', 'start_date' => '2026-10-16', 'end_date' => '2026-10-31',
        ]);
        $october = Quinzaine::where('label', 'Q2 oct')->sole();
        $this->assertSame($october->id, $this->salaryRecords($worker)->sole()->quinzaine_id);
    }

    public function test_grid_payslip_and_invoicing_treat_the_salary_apart_from_the_days(): void
    {
        $worker = $this->worker();
        $this->cell($worker, $this->second, '2026-09-17');
        $this->setSalary($worker, 3500);

        $this->actingAs($this->manager)->get(route('pointage.grid', $this->second->id))
            ->assertInertia(fn ($p) => $p->where("salairesFixes.{$worker->id}", 3500)->has("existingRecords.{$worker->id}", 1));

        $records = PointageRecord::where('employee_id', $worker->id)->get();
        $payslip = app(PayrollService::class)->payslipBreakdown('avec_contrat', $records, 43.75, 3500.0);
        $this->assertEquals(3500, $payslip['salaire_fixe']);
        $this->assertEquals(3500, $payslip['total_net']);
        $this->assertEquals(3500, $payslip['total_gains']);
        $this->assertSame(1, app(PayrollService::class)->invoicingForRecords('avec_contrat', $records, 43.75, true)['days']);
    }
}
