<?php

namespace Tests\Feature\Stock;

use App\Models\Bloc;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\Product;
use App\Models\User;
use App\Services\StockReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Every stock report covers a chosen period: a month by default, a quinzaine, two dates or all. */
class StockReportPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $manager;

    private Product $engrais;

    private Bloc $bloc;

    private Operation $operation;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25');

        $this->farm = Farm::create(['name' => 'Test Farm']);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->engrais = Product::create(['farm_id' => $this->farm->id, 'name' => 'Engrais', 'unit_type' => 'kg', 'min_stock_level' => 0, 'unit_cost' => 99, 'is_active' => true]);
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1', 'area_ha' => 10]);
        $this->operation = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Fertilisation', 'abbreviation' => 'F']);

        // September: 100 kg in at 10, 30 out. October: 50 in at 16 (CUMP 12.5), 20 out on the 3rd, 10 on the 20th.
        $this->reception(100, 10, '2026-09-05');
        $this->sortie(30, '2026-09-10');
        $this->reception(50, 16, '2026-10-01');
        $this->sortie(20, '2026-10-03');
        $this->sortie(10, '2026-10-20');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reception(float $quantity, float $cost, string $date): void
    {
        $this->actingAs($this->manager)->post(route('stock.movements.in'), [
            'product_id' => $this->engrais->id, 'quantity' => $quantity, 'unit_cost' => $cost, 'date' => $date,
        ])->assertSessionHasNoErrors();
    }

    private function sortie(float $quantity, string $date): void
    {
        $this->actingAs($this->manager)->post(route('stock.manual-entries.store'), [
            'product_id' => $this->engrais->id, 'entry_type' => 'consumption', 'quantity' => $quantity, 'date' => $date,
            'bloc_id' => $this->bloc->id, 'operation_id' => $this->operation->id,
        ])->assertSessionHasNoErrors();
    }

    public function test_the_period_is_a_month_a_quinzaine_two_dates_or_everything(): void
    {
        $month = StockReportPeriod::fromInput([]);
        $this->assertSame(['mois', '2026-10-01', '2026-10-31', 'Octobre 2026'], [$month->periode, $month->from->toDateString(), $month->to->toDateString(), $month->label]);

        $q = StockReportPeriod::fromInput(['periode' => 'quinzaine', 'quinzaine' => '2026-02-2']);
        $this->assertSame(['2026-02-16', '2026-02-28', '2ème quinzaine février 2026'], [$q->from->toDateString(), $q->to->toDateString(), $q->label]);
        $this->assertSame('2026-10-2', StockReportPeriod::fromInput(['periode' => 'quinzaine'])->toArray()['quinzaine']);

        $dates = StockReportPeriod::fromInput(['periode' => 'dates', 'from' => '2026-10-20', 'to' => '2026-10-02']);
        $this->assertSame(['2026-10-02', '2026-10-20'], [$dates->from->toDateString(), $dates->to->toDateString()]);

        $all = StockReportPeriod::fromInput(['periode' => 'tout']);
        $this->assertNull($all->from);
        $this->assertNull($all->to);
    }

    public function test_consumption_and_cost_reports_cover_only_the_period(): void
    {
        $this->actingAs($this->manager)->get(route('stock.reports.consumption-by-operation'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.label', 'Octobre 2026')
                ->where('consumptionByOperation.0.total_quantity_consumed', 30));
        $this->actingAs($this->manager)->get(route('stock.reports.consumption-by-operation', ['periode' => 'quinzaine', 'quinzaine' => '2026-10-1']))
            ->assertInertia(fn (Assert $page) => $page->where('consumptionByOperation.0.total_quantity_consumed', 20));
        $this->actingAs($this->manager)->get(route('stock.reports.consumption-by-operation', ['periode' => 'tout']))
            ->assertInertia(fn (Assert $page) => $page->where('consumptionByOperation.0.total_quantity_consumed', 60));

        // At what the sorties cost (CUMP (70×10 + 50×16) / 120 = 12.5 in October), not the catalog's 99.
        $this->actingAs($this->manager)->get(route('stock.reports.cost-per-hectare'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('costPerHectareData.0.total_cost', fn ($cost) => abs($cost - 375) < 0.01)
                ->where('costPerHectareData.0.cost_per_hectare', fn ($cost) => abs($cost - 37.5) < 0.01));
    }

    public function test_the_movement_history_is_paginated_with_its_totals_over_the_period(): void
    {
        foreach (range(1, 60) as $i) {
            $this->reception(1, 12, '2026-10-10');
        }

        $this->actingAs($this->manager)->get(route('stock.reports.movement-history', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('movementHistory.data', 13) // 61 in + 2 out in October
                ->where('movementHistory.total', 63)
                ->where('stats.in.count', 61)
                ->where('stats.out.count', 2));
        $this->actingAs($this->manager)->get(route('stock.reports.movement-history', ['periode' => 'mois', 'mois' => '2026-09', 'movement_type' => 'out']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('movementHistory.total', 1)
                ->where('movementType', 'out')
                ->where('stats.in.count', 1));
    }

    public function test_the_inventory_value_and_turnover_use_the_stock_as_it_stood(): void
    {
        // End of September: 100 - 30 = 70 kg (today: 90).
        $this->actingAs($this->manager)->get(route('stock.reports.inventory-value', ['periode' => 'mois', 'mois' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('asOf', '2026-09-30')
                ->where('inventoryValue.0.quantity_on_hand', fn ($q) => abs($q - 70) < 0.01));
        $this->actingAs($this->manager)->get(route('stock.reports.inventory-value'))
            ->assertInertia(fn (Assert $page) => $page->where('inventoryValue.0.quantity_on_hand', fn ($q) => abs($q - 90) < 0.01));

        // October: 70 at the start, 90 at the end (only up to today), 50 in, 30 out.
        $this->actingAs($this->manager)->get(route('stock.reports.stock-turnover'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('days', 25)
                ->where('stockTurnoverData.0.beginning_inventory', fn ($q) => abs($q - 70) < 0.01)
                ->where('stockTurnoverData.0.ending_inventory', fn ($q) => abs($q - 90) < 0.01)
                ->where('stockTurnoverData.0.purchases', fn ($q) => abs($q - 50) < 0.01)
                ->where('stockTurnoverData.0.sales_consumption', fn ($q) => abs($q - 30) < 0.01));
    }
}
