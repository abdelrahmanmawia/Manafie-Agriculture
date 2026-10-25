<?php

namespace Tests\Feature\Stock;

use App\Models\Farm;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FuelConsumptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Consumption from the meter read at each fill-up: L/h for a tractor, L/100 km for a car; odd fill-ups flagged. */
class FuelConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $manager;

    private Vehicle $tracteur;

    private Vehicle $voiture;

    private Product $gasoil;

    private int $day = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Test Farm']);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->tracteur = Vehicle::create(['farm_id' => $this->farm->id, 'name' => 'Tracteur', 'plate_number' => 'T-1', 'type' => 'tractor', 'fuel_type' => 'diesel', 'capacity_liters' => 120, 'is_active' => true]);
        $this->gasoil = Product::create(['farm_id' => $this->farm->id, 'name' => 'Gasoil', 'unit_type' => 'liters', 'min_stock_level' => 0, 'unit_cost' => 12, 'is_active' => true]);
        $this->voiture = Vehicle::create(['farm_id' => $this->farm->id, 'name' => 'Dacia', 'plate_number' => 'D-1', 'type' => 'car', 'fuel_type' => 'diesel', 'is_active' => true]);
    }

    private function plein(Vehicle $vehicle, float $liters, ?float $reading): FuelTransaction
    {
        return FuelTransaction::create([
            'farm_id' => $this->farm->id, 'vehicle_id' => $vehicle->id, 'product_id' => $this->gasoil->id, 'transaction_type' => 'fueling',
            'quantity_liters' => $liters, 'unit_price_per_liter' => 12, 'total_cost' => $liters * 12,
            'performed_by' => $this->manager->id, 'date' => '2026-10-'.str_pad((string) ++$this->day, 2, '0', STR_PAD_LEFT), 'odometer_km' => $reading,
        ]);
    }

    private function analysis()
    {
        return FuelConsumptionService::analyse(FuelConsumptionService::farmFillUps($this->farm->id));
    }

    public function test_a_tractor_burns_liters_per_hour_and_an_unusual_fill_up_is_flagged(): void
    {
        $this->plein($this->tracteur, 60, 100); // first reading: nothing to compare with
        foreach ([106, 112, 118] as $reading) {
            $this->plein($this->tracteur, 60, $reading);
        }
        $high = $this->plein($this->tracteur, 75, 121); // 25 L/h, usual 10

        $analysis = $this->analysis();
        $this->assertNull($analysis->first()['rate']);
        $this->assertEqualsWithDelta(10, $analysis->values()[1]['rate'], 0.01);
        $this->assertSame('h', $analysis[$high->id]['unit']);
        $this->assertEqualsWithDelta(25, $analysis[$high->id]['rate'], 0.01);
        $this->assertSame(['Consommation élevée : 25 L/h (habituel 10)'], $analysis[$high->id]['alertes']);
        $this->assertSame(1, $analysis->filter(fn ($r) => $r['alertes'])->count());
    }

    public function test_a_car_burns_liters_per_100_km_and_a_fill_without_reading_counts_toward_the_next(): void
    {
        $this->plein($this->voiture, 40, 50000);
        $this->plein($this->voiture, 20, null);
        $next = $this->plein($this->voiture, 30, 50500); // 50 L over 500 km

        $analysis = $this->analysis();
        $this->assertSame('km', $analysis[$next->id]['unit']);
        $this->assertEqualsWithDelta(10, $analysis[$next->id]['rate'], 0.01);
        $fills = FuelConsumptionService::farmFillUps($this->farm->id)->where('vehicle_id', $this->voiture->id);
        $this->assertEqualsWithDelta(10, FuelConsumptionService::average($fills, $analysis), 0.01);
    }

    public function test_a_meter_going_back_or_not_moving_and_more_than_the_tank_are_flagged(): void
    {
        $this->plein($this->tracteur, 60, 200);
        $back = $this->plein($this->tracteur, 60, 190);
        $same = $this->plein($this->tracteur, 60, 200);
        $tank = $this->plein($this->tracteur, 140, 214);

        $analysis = $this->analysis();
        $this->assertSame(['Compteur en recul (200 → 190)'], $analysis[$back->id]['alertes']);
        $this->assertSame(['Compteur inchangé depuis le plein précédent'], $analysis[$same->id]['alertes']);
        $this->assertSame(['Plus que le réservoir (120 L)'], $analysis[$tank->id]['alertes']);
        $this->assertEqualsWithDelta(10, $analysis[$tank->id]['rate'], 0.01); // 140 L over 14 h since 200
    }

    public function test_the_carburant_page_shows_each_rate_and_filters_the_fill_ups_to_check(): void
    {
        $this->plein($this->tracteur, 60, 200);
        $ok = $this->plein($this->tracteur, 60, 206);
        $back = $this->plein($this->tracteur, 60, 190);

        $this->actingAs($this->manager)->get(route('stock.fuel-transactions.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('anomaliesCount', 1)
                ->where("consommation.{$ok->id}.rate", 10)
                ->where("consommation.{$ok->id}.label", 'L/h'));
        $this->actingAs($this->manager)->get(route('stock.fuel-transactions.index', ['anomalies' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('fuelTransactions.total', 1)
                ->where('fuelTransactions.data.0.id', $back->id));
    }

    public function test_the_cost_per_vehicle_report_gives_the_average_over_the_period(): void
    {
        $this->plein($this->voiture, 40, 50000);
        $this->plein($this->voiture, 45, 50600); // 7,5 L/100 km

        $this->actingAs($this->manager)->get(route('stock.reports.cost-per-vehicle', ['periode' => 'mois', 'mois' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('costPerVehicleData', fn ($rows) => collect($rows)->contains(fn ($r) => $r['vehicle_name'] === 'Dacia'
                    && abs($r['consommation'] - 7.5) < 0.01 && $r['consommation_unite'] === 'L/100 km' && $r['pleins_a_verifier'] === 0)));
    }
}
