<?php

namespace Tests\Feature;

use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Quinzaine;
use App\Models\TransportCompany;
use App\Models\TransportVehicle;
use App\Models\TransportVehicleAbsence;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The transport absence and vehicle usage grids send the wanted state of a cell
 * (absent/used), not "toggle": repeating a request — a double-click, a retry — must leave the
 * cell as asked instead of flipping it back.
 */
class GridCellStateTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;
    private User $manager;
    private Quinzaine $quinzaine;
    private TransportVehicle $bus;
    private Vehicle $tractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $enterprise = Enterprise::create([
            'farm_id' => $this->farm->id, 'name' => 'Division', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 100,
        ]);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->quinzaine = Quinzaine::create([
            'enterprise_id' => $enterprise->id, 'label' => '1QZ', 'start_date' => '2026-01-01', 'end_date' => '2026-01-15', 'is_closed' => false,
        ]);
        $company = TransportCompany::create(['farm_id' => $this->farm->id, 'name' => 'Transco']);
        $this->bus = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $company->id, 'code' => 'T1']);
        $this->tractor = Vehicle::create([
            'farm_id' => $this->farm->id, 'name' => 'Tracteur', 'type' => 'tractor', 'is_active' => true, 'default_daily_rate' => 350,
        ]);
    }

    private function absence(bool $absent)
    {
        return $this->actingAs($this->manager)->post('/transport/absence', [
            'transport_vehicle_id' => $this->bus->id,
            'quinzaine_id' => $this->quinzaine->id,
            'date' => '2026-01-05',
            'absent' => $absent,
        ]);
    }

    private function usage(bool $used)
    {
        return $this->actingAs($this->manager)->post(route('stock.vehicle-usage.cell'), [
            'vehicle_id' => $this->tractor->id,
            'date' => '2026-01-05',
            'used' => $used,
        ]);
    }

    // --- Transport absence ---

    public function test_marking_absence_twice_keeps_it_marked(): void
    {
        $this->absence(true)->assertSessionHasNoErrors();
        $this->absence(true)->assertSessionHasNoErrors();

        $this->assertSame(1, TransportVehicleAbsence::count());
    }

    public function test_unmarking_absence_twice_keeps_it_unmarked(): void
    {
        $this->absence(true);

        $this->absence(false)->assertSessionHasNoErrors();
        $this->absence(false)->assertSessionHasNoErrors();

        $this->assertSame(0, TransportVehicleAbsence::count());
    }

    public function test_absence_requires_the_wanted_state(): void
    {
        $this->actingAs($this->manager)->post('/transport/absence', [
            'transport_vehicle_id' => $this->bus->id,
            'quinzaine_id' => $this->quinzaine->id,
            'date' => '2026-01-05',
        ])->assertSessionHasErrors('absent');

        $this->assertSame(0, TransportVehicleAbsence::count());
    }

    // --- Vehicle usage ---

    public function test_marking_vehicle_usage_twice_keeps_one_day_at_the_default_rate(): void
    {
        $this->usage(true)->assertSessionHasNoErrors();
        $this->usage(true)->assertSessionHasNoErrors();

        $this->assertSame(1, VehicleUsage::count());
        $this->assertEquals(350, VehicleUsage::sole()->daily_rate);
    }

    public function test_unmarking_vehicle_usage_twice_keeps_it_unmarked(): void
    {
        $this->usage(true);

        $this->usage(false)->assertSessionHasNoErrors();
        $this->usage(false)->assertSessionHasNoErrors();

        $this->assertSame(0, VehicleUsage::count());
    }

    public function test_unmarking_does_not_need_a_daily_rate(): void
    {
        $this->tractor->update(['default_daily_rate' => null]);

        $this->usage(false)->assertSessionHasNoErrors();
        $this->usage(true)->assertSessionHasErrors('daily_rate');
    }

    public function test_vehicle_usage_requires_the_wanted_state(): void
    {
        $this->actingAs($this->manager)->post(route('stock.vehicle-usage.cell'), [
            'vehicle_id' => $this->tractor->id,
            'date' => '2026-01-05',
        ])->assertSessionHasErrors('used');
    }
}
