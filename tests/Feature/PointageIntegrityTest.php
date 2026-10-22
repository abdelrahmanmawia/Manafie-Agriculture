<?php

namespace Tests\Feature;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\QuinzaineSummary;
use App\Models\TransportCompany;
use App\Models\TransportVehicle;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\TransportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pointage write-path integrity: dates bounded to their quinzaine, quinzaine close atomic with
 * its snapshots, the offline scan station never overwriting a hand-entered Grid record (and its
 * token scoped/expiring), and snapshot invoicing ignoring piece-rate days.
 */
class PointageIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $enterprise;

    private User $manager;

    private Employee $employee;

    private Operation $operation;

    private Bloc $bloc;

    private Quinzaine $quinzaine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->enterprise = Enterprise::create([
            'farm_id' => $this->farm->id, 'name' => 'Division', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 100,
        ]);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->employee = Employee::create([
            'farm_id' => $this->farm->id, 'enterprise_id' => $this->enterprise->id, 'matricule' => '001',
            'full_name' => 'Worker', 'base_rate' => 100, 'complement' => 10, 'is_active' => true, 'badge_uuid' => 'badge-1',
        ]);
        $this->operation = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Taille']);
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        $this->quinzaine = Quinzaine::create([
            'enterprise_id' => $this->enterprise->id, 'label' => '1QZ', 'start_date' => '2026-01-01',
            'end_date' => '2026-01-15', 'is_closed' => false,
        ]);
    }

    private function cell(array $overrides = [])
    {
        return $this->actingAs($this->manager)->post('/pointage/cell', array_merge([
            'employee_id' => $this->employee->id,
            'quinzaine_id' => $this->quinzaine->id,
            'operation_id' => $this->operation->id,
            'bloc_id' => $this->bloc->id,
            'date' => '2026-01-05',
        ], $overrides));
    }

    private function scanToken(User $user, $expiresAt = null): string
    {
        return $user->createToken('scan-station', ["farm:{$this->farm->id}"], $expiresAt ?? now()->addDay())->plainTextToken;
    }

    private function sync(string $token, array $scanOverrides = [])
    {
        return $this->withToken($token)->postJson('/api/pointage/scan-station/sync', [
            'scans' => [array_merge([
                'scan_uuid' => 'scan-1',
                'badge_uuid' => 'badge-1',
                'scanned_at' => '2026-01-05T08:00:00Z',
                'bloc_id' => $this->bloc->id,
                'operation_id' => $this->operation->id,
            ], $scanOverrides)],
        ]);
    }

    // --- Dates bounded to the quinzaine ---

    public function test_cell_dated_outside_its_quinzaine_is_rejected(): void
    {
        $this->cell(['date' => '2026-02-03'])->assertSessionHasErrors('date');

        $this->assertDatabaseCount('pointage_records', 0);
    }

    public function test_bulk_cells_with_a_date_outside_the_quinzaine_are_rejected(): void
    {
        $this->actingAs($this->manager)->post('/pointage/cell-bulk', [
            'employee_id' => $this->employee->id,
            'quinzaine_id' => $this->quinzaine->id,
            'operation_id' => $this->operation->id,
            'bloc_id' => $this->bloc->id,
            'dates' => ['2026-01-06', '2026-01-20'],
        ])->assertSessionHasErrors('date');

        $this->assertDatabaseCount('pointage_records', 0);
    }

    public function test_editing_an_existing_cell_updates_it_in_place(): void
    {
        $this->cell(['hours' => 1]);
        $this->cell(['hours' => 4])->assertSessionHasNoErrors();

        $this->assertEquals(4, PointageRecord::sole()->hours);
    }

    public function test_cell_on_the_last_day_of_the_quinzaine_is_accepted(): void
    {
        $this->cell(['date' => '2026-01-15'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('pointage_records', 1);
    }

    public function test_transport_attendance_outside_the_quinzaine_is_rejected(): void
    {
        $company = TransportCompany::create(['farm_id' => $this->farm->id, 'name' => 'Transco']);
        $vehicle = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $company->id, 'code' => 'T1']);

        $this->actingAs($this->manager)->post('/transport/attendance/toggle', [
            'transport_vehicle_id' => $vehicle->id,
            'quinzaine_id' => $this->quinzaine->id,
            'date' => '2026-01-20',
        ])->assertSessionHasErrors('date');

        $this->assertDatabaseCount('transport_vehicle_attendances', 0);
    }

    // --- Quinzaine close ---

    public function test_a_failed_snapshot_rolls_the_close_back(): void
    {
        $this->cell();
        $this->mock(TransportService::class, fn ($mock) => $mock->shouldReceive('generateSnapshot')->andThrow(new \RuntimeException('boom')));

        $this->actingAs($this->manager)
            ->post('/settings/quinzaine/'.$this->quinzaine->id.'/close')
            ->assertStatus(500);

        $this->assertFalse($this->quinzaine->fresh()->is_closed);
        $this->assertDatabaseCount('quinzaine_summaries', 0);
    }

    public function test_closing_writes_the_snapshot_and_blocks_further_cells(): void
    {
        $this->cell();

        $this->actingAs($this->manager)->post('/settings/quinzaine/'.$this->quinzaine->id.'/close')->assertRedirect();

        $this->assertTrue($this->quinzaine->fresh()->is_closed);
        $this->assertDatabaseHas('quinzaine_summaries', ['quinzaine_id' => $this->quinzaine->id]);
        $this->cell(['date' => '2026-01-06'])->assertSessionHasErrors('date');
        $this->assertDatabaseCount('pointage_records', 1);
    }

    // --- Scan station ---

    public function test_scan_never_overwrites_a_hand_entered_grid_record(): void
    {
        $this->cell(['hours' => 2]);

        $this->sync($this->scanToken($this->manager))
            ->assertOk()
            ->assertJsonPath('results.0.ok', true);

        $record = PointageRecord::sole();
        $this->assertEquals(2, $record->hours);
        $this->assertNull($record->scan_uuid);
    }

    public function test_rescanning_updates_the_scan_record_instead_of_duplicating(): void
    {
        $token = $this->scanToken($this->manager);
        $otherBloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B2']);

        $this->sync($token)->assertJsonPath('results.0.ok', true);
        $this->sync($token, ['scan_uuid' => 'scan-2', 'bloc_id' => $otherBloc->id])->assertJsonPath('results.0.ok', true);

        $this->assertSame($otherBloc->id, PointageRecord::sole()->bloc_id);
    }

    public function test_grid_edit_of_a_scanned_record_makes_it_manual(): void
    {
        $token = $this->scanToken($this->manager);
        $this->sync($token);

        $this->cell(['hours' => 3]);
        $this->sync($token, ['scan_uuid' => 'scan-2']);

        $this->assertEquals(3, PointageRecord::sole()->hours);
    }

    public function test_division_locked_user_cannot_scan_another_divisions_employee(): void
    {
        $otherDivision = Enterprise::create([
            'farm_id' => $this->farm->id, 'name' => 'Other', 'contract_type' => 'sans_contrat', 'default_brut_rate' => 90,
        ]);
        $pointeur = User::factory()->create([
            'role' => 'data_entry', 'farm_id' => $this->farm->id, 'enterprise_id' => $otherDivision->id, 'can_access_pointage' => true,
        ]);

        $this->sync($this->scanToken($pointeur))
            ->assertOk()
            ->assertJsonPath('results.0.ok', false);

        $this->assertDatabaseCount('pointage_records', 0);
    }

    public function test_expired_scan_token_is_rejected(): void
    {
        $this->sync($this->scanToken($this->manager, now()->subMinute()))->assertUnauthorized();
    }

    public function test_user_who_lost_pointage_access_cannot_sync(): void
    {
        $user = User::factory()->create([
            'role' => 'data_entry', 'farm_id' => $this->farm->id, 'can_access_pointage' => true,
        ]);
        $token = $this->scanToken($user);
        $user->update(['can_access_pointage' => false]);

        $this->sync($token)->assertForbidden();
    }

    public function test_scan_from_the_future_is_rejected(): void
    {
        $this->sync($this->scanToken($this->manager), ['scanned_at' => now()->addDay()->toIso8601String()])
            ->assertUnprocessable();
    }

    public function test_issued_scan_token_expires(): void
    {
        $this->actingAs($this->manager)->postJson('/pointage/scan-station/token')->assertOk();

        $expiresAt = $this->manager->tokens()->sole()->expires_at;
        $this->assertNotNull($expiresAt);
        $this->assertTrue($expiresAt->between(now()->addDays(29), now()->addDays(31)));
    }

    // --- Snapshot invoicing ---

    public function test_snapshot_invoicing_ignores_piece_rate_days_even_when_first(): void
    {
        $this->enterprise->update(['invoiced_to_client' => true]);
        $pieceOp = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Cueillette', 'unit_rate' => 5]);

        // Piece-rate record created FIRST — its rate (5, the per-unit price) used to become the
        // brut rate the whole period was invoiced at.
        $this->cell(['date' => '2026-01-02', 'operation_id' => $pieceOp->id, 'quantity' => 40]);
        $this->cell(['date' => '2026-01-03']);
        $this->cell(['date' => '2026-01-04']);

        $service = app(PayrollService::class);
        $service->generateSnapshot($this->quinzaine->fresh());

        $expected = $service->calculateInvoicing('avec_contrat', 100, 10, 2, 0, 0, true)['total_ttc'];
        $this->assertEqualsWithDelta($expected, QuinzaineSummary::sole()->total_ttc, 0.01);
    }
}
