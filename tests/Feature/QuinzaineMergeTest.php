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

/**
 * Regression coverage for the audit finding that Analytics and the farm Accueil dashboard merged
 * same-period quinzaines by (start_date, label) instead of (start_date, end_date). Two divisions'
 * quinzaines for the same real-world period can carry slightly different free-text labels, which
 * used to fragment one period into two separate trend points instead of merging them into one —
 * exactly the bug PayrollController::history() already avoided by keying on the date range instead.
 */
class QuinzaineMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_trend_merges_same_period_quinzaines_with_different_labels(): void
    {
        $farm = Farm::create(['name' => 'Farm A']);
        $enterpriseX = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise X',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 100,
        ]);
        $enterpriseY = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise Y',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 100,
        ]);
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);
        $bloc = Bloc::create(['farm_id' => $farm->id, 'name' => 'Bloc A']);
        $operation = Operation::create(['farm_id' => $farm->id, 'name' => 'Op A']);

        $employeeX = Employee::create([
            'enterprise_id' => $enterpriseX->id, 'matricule' => 'X-1', 'full_name' => 'Employee X',
            'type' => 'persea', 'base_rate' => 100, 'is_active' => true,
        ]);
        $employeeY = Employee::create([
            'enterprise_id' => $enterpriseY->id, 'matricule' => 'Y-1', 'full_name' => 'Employee Y',
            'type' => 'persea', 'base_rate' => 100, 'is_active' => true,
        ]);

        // Same real-world period, deliberately mismatched free-text labels across divisions.
        $quinzaineX = Quinzaine::create([
            'enterprise_id' => $enterpriseX->id, 'label' => '1QZ Janvier',
            'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false,
        ]);
        $quinzaineY = Quinzaine::create([
            'enterprise_id' => $enterpriseY->id, 'label' => '1QZ Jan (saisie manuelle)',
            'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false,
        ]);

        PointageRecord::create([
            'employee_id' => $employeeX->id, 'quinzaine_id' => $quinzaineX->id,
            'operation_id' => $operation->id, 'bloc_id' => $bloc->id, 'date' => '2026-01-05',
            'hours' => 0, 'is_jf' => false, 'rate' => 100, 'brut' => 100, 'net' => 100,
        ]);
        PointageRecord::create([
            'employee_id' => $employeeY->id, 'quinzaine_id' => $quinzaineY->id,
            'operation_id' => $operation->id, 'bloc_id' => $bloc->id, 'date' => '2026-01-05',
            'hours' => 0, 'is_jf' => false, 'rate' => 100, 'brut' => 100, 'net' => 200,
        ]);

        $response = $this->actingAs($manager)->get('/analytics');
        $response->assertOk();

        $trend = $response->viewData('page')['props']['trend'];
        $this->assertCount(1, $trend, 'Same-period quinzaines with different labels should merge into one trend point.');
        $this->assertEquals(300, $trend[0]->total_net);
    }

    public function test_farm_dashboard_merges_same_period_quinzaines_with_different_labels(): void
    {
        $farm = Farm::create(['name' => 'Farm A']);
        $enterpriseX = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise X',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 100,
        ]);
        $enterpriseY = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise Y',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 100,
        ]);
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);
        $bloc = Bloc::create(['farm_id' => $farm->id, 'name' => 'Bloc A']);
        $operation = Operation::create(['farm_id' => $farm->id, 'name' => 'Op A']);

        $employeeX = Employee::create([
            'enterprise_id' => $enterpriseX->id, 'matricule' => 'X-1', 'full_name' => 'Employee X',
            'type' => 'persea', 'base_rate' => 100, 'is_active' => true,
        ]);
        $employeeY = Employee::create([
            'enterprise_id' => $enterpriseY->id, 'matricule' => 'Y-1', 'full_name' => 'Employee Y',
            'type' => 'persea', 'base_rate' => 100, 'is_active' => true,
        ]);

        $quinzaineX = Quinzaine::create([
            'enterprise_id' => $enterpriseX->id, 'label' => '1QZ Janvier',
            'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false,
        ]);
        $quinzaineY = Quinzaine::create([
            'enterprise_id' => $enterpriseY->id, 'label' => '1QZ Jan (saisie manuelle)',
            'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false,
        ]);

        PointageRecord::create([
            'employee_id' => $employeeX->id, 'quinzaine_id' => $quinzaineX->id,
            'operation_id' => $operation->id, 'bloc_id' => $bloc->id, 'date' => '2026-01-05',
            'hours' => 0, 'is_jf' => false, 'rate' => 100, 'brut' => 100, 'net' => 100,
        ]);
        PointageRecord::create([
            'employee_id' => $employeeY->id, 'quinzaine_id' => $quinzaineY->id,
            'operation_id' => $operation->id, 'bloc_id' => $bloc->id, 'date' => '2026-01-05',
            'hours' => 0, 'is_jf' => false, 'rate' => 100, 'brut' => 100, 'net' => 200,
        ]);

        $response = $this->actingAs($manager)->get('/dashboard');
        $response->assertOk();

        $trend = $response->viewData('page')['props']['payrollTrend'];
        $this->assertCount(1, $trend, 'Same-period quinzaines with different labels should merge into one farm dashboard trend point.');
        $this->assertEquals(300, $trend[0]['total_net']);
    }
}
