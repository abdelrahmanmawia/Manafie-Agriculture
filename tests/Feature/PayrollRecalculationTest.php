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
 * Regression coverage for the "stale net after edit" audit finding: PointageRecord.rate/brut/net
 * are a denormalized snapshot of PayrollService::calculate(), written once when a cell is entered.
 * If the enterprise's default_brut_rate/contract_type or an employee's complement changes later,
 * every previously-entered record used to keep its old (now-wrong) net/brut forever, silently
 * diverging from what a payslip PDF (which always recalculates live) would show for the same day.
 */
class PayrollRecalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_enterprise_rate_recalculates_open_quinzaine_records(): void
    {
        $farm = Farm::create(['name' => 'Farm A']);
        $enterprise = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise A',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 100,
        ]);
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);
        $employee = Employee::create([
            'enterprise_id' => $enterprise->id, 'matricule' => 'A-1', 'full_name' => 'Employee A',
            'type' => 'persea', 'base_rate' => 100, 'complement' => 0, 'is_active' => true,
        ]);
        $bloc = Bloc::create(['farm_id' => $farm->id, 'name' => 'Bloc A']);
        $operation = Operation::create(['farm_id' => $farm->id, 'name' => 'Op A']);

        $openQuinzaine = Quinzaine::create([
            'enterprise_id' => $enterprise->id, 'label' => '1QZ Open',
            'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false,
        ]);
        $closedQuinzaine = Quinzaine::create([
            'enterprise_id' => $enterprise->id, 'label' => '2QZ Closed',
            'start_date' => '2025-12-01', 'end_date' => '2025-12-15', 'is_closed' => true,
        ]);

        $svc = new PayrollService();
        $oldCalc = $svc->calculate('avec_contrat', 100, 0, 0, false);

        $openRecord = PointageRecord::create([
            'employee_id' => $employee->id, 'quinzaine_id' => $openQuinzaine->id,
            'operation_id' => $operation->id, 'bloc_id' => $bloc->id, 'date' => '2026-01-05',
            'hours' => 0, 'is_jf' => false, 'rate' => 100,
            'brut' => $oldCalc['brut'], 'net' => $oldCalc['total_net'],
        ]);
        $closedRecord = PointageRecord::create([
            'employee_id' => $employee->id, 'quinzaine_id' => $closedQuinzaine->id,
            'operation_id' => $operation->id, 'bloc_id' => $bloc->id, 'date' => '2025-12-05',
            'hours' => 0, 'is_jf' => false, 'rate' => 100,
            'brut' => $oldCalc['brut'], 'net' => $oldCalc['total_net'],
        ]);

        $this->actingAs($manager)
            ->patch('/enterprises/' . $enterprise->id, [
                'name' => 'Enterprise A', 'default_brut_rate' => 200, 'contract_type' => 'avec_contrat',
            ])
            ->assertRedirect();

        $newCalc = $svc->calculate('avec_contrat', 200, 0, 0, false);

        $openRecord->refresh();
        $this->assertEquals($newCalc['total_net'], $openRecord->net);
        $this->assertEquals(200, $openRecord->rate);

        // Closed quinzaine's history must stay frozen at whatever it was when it was closed.
        $closedRecord->refresh();
        $this->assertEquals($oldCalc['total_net'], $closedRecord->net);
        $this->assertEquals(100, $closedRecord->rate);
    }

    public function test_changing_employee_complement_recalculates_open_quinzaine_records(): void
    {
        $farm = Farm::create(['name' => 'Farm A']);
        $enterprise = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise A',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);
        $employee = Employee::create([
            'enterprise_id' => $enterprise->id, 'matricule' => 'A-1', 'full_name' => 'Employee A',
            'type' => 'persea', 'base_rate' => 97.44, 'complement' => 0, 'is_active' => true,
        ]);
        $bloc = Bloc::create(['farm_id' => $farm->id, 'name' => 'Bloc A']);
        $operation = Operation::create(['farm_id' => $farm->id, 'name' => 'Op A']);

        $openQuinzaine = Quinzaine::create([
            'enterprise_id' => $enterprise->id, 'label' => '1QZ Open',
            'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false,
        ]);

        $svc = new PayrollService();
        $oldCalc = $svc->calculate('avec_contrat', 97.44, 0, 0, false);

        $openRecord = PointageRecord::create([
            'employee_id' => $employee->id, 'quinzaine_id' => $openQuinzaine->id,
            'operation_id' => $operation->id, 'bloc_id' => $bloc->id, 'date' => '2026-01-05',
            'hours' => 0, 'is_jf' => false, 'rate' => 97.44,
            'brut' => $oldCalc['brut'], 'net' => $oldCalc['total_net'],
        ]);

        $this->actingAs($manager)
            ->put('/employees/' . $employee->id, [
                'matricule' => 'A-1', 'full_name' => 'Employee A', 'type' => 'persea',
                'base_rate' => 97.44, 'complement' => 15, 'enterprise_id' => $enterprise->id,
            ])
            ->assertRedirect();

        $newCalc = $svc->calculate('avec_contrat', 97.44, 0, 15, false);

        $openRecord->refresh();
        $this->assertEqualsWithDelta($newCalc['total_net'], $openRecord->net, 0.001);
        $this->assertNotEquals($oldCalc['total_net'], $openRecord->net);
    }
}
