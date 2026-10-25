<?php

namespace Tests\Feature\Stock;

use App\Models\Farm;
use App\Models\Product;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** A wrong réception is cancelled by a manager: its quantity and value come back out, the CUMP without it. */
class ReceptionAnnulationTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $manager;

    private User $magasinier;

    private Product $engrais;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25');

        $this->farm = Farm::create(['name' => 'Test Farm']);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->magasinier = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id]);
        $this->engrais = Product::create(['farm_id' => $this->farm->id, 'name' => 'Engrais', 'unit_type' => 'kg', 'min_stock_level' => 0, 'unit_cost' => 99, 'is_active' => true]);
        $this->supplier = Supplier::create(['farm_id' => $this->farm->id, 'name' => 'Fournisseur', 'is_active' => true]);

        $this->reception(100, 10, '2026-10-01');
        $this->reception(50, 16, '2026-10-10', 'BL-77'); // CUMP (1000 + 800) / 150 = 12
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reception(float $quantity, float $cost, string $date, ?string $bl = null): void
    {
        $this->actingAs($this->magasinier)->post(route('stock.movements.in'), [
            'product_id' => $this->engrais->id, 'quantity' => $quantity, 'unit_cost' => $cost, 'date' => $date,
            'supplier_id' => $this->supplier->id, 'numero_bl' => $bl,
        ])->assertSessionHasNoErrors();
    }

    private function annuler(StockMovement $movement, ?User $user = null, string $motif = 'Saisie en double')
    {
        return $this->actingAs($user ?? $this->manager)->post(route('stock.movements.annuler', $movement), ['motif' => $motif]);
    }

    private function inventory(): StockInventory
    {
        return StockInventory::where('product_id', $this->engrais->id)->sole();
    }

    private function wrong(): StockMovement
    {
        return StockMovement::where('numero_bl', 'BL-77')->sole();
    }

    public function test_cancelling_takes_the_quantity_and_value_out_and_restores_the_cump(): void
    {
        $this->assertEqualsWithDelta(12, $this->inventory()->average_cost, 0.01);

        $this->annuler($this->wrong())->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertEqualsWithDelta(100, $this->inventory()->quantity_on_hand, 0.01);
        $this->assertEqualsWithDelta(10, $this->inventory()->average_cost, 0.01);
        $annulation = StockMovement::where('movement_type', 'adjustment')->sole();
        $this->assertEqualsWithDelta(-50, $annulation->quantity, 0.01);
        $this->assertEqualsWithDelta(-800, $annulation->total_cost, 0.01);
        $this->assertSame('Annulation de la réception du 10/10/2026 (BL BL-77) : Saisie en double', $annulation->notes);
        $this->assertSame($annulation->id, $this->wrong()->annulation_id);
    }

    public function test_a_reception_is_cancelled_once_and_only_a_reception(): void
    {
        $this->annuler($this->wrong());
        $this->annuler($this->wrong())->assertSessionHasErrors('motif');
        $this->assertEqualsWithDelta(100, $this->inventory()->quantity_on_hand, 0.01);

        $this->actingAs($this->manager)->post(route('stock.manual-entries.store'), [
            'product_id' => $this->engrais->id, 'entry_type' => 'consumption', 'quantity' => 5, 'date' => '2026-10-25',
        ]);
        $this->annuler(StockMovement::where('movement_type', 'out')->sole())->assertSessionHasErrors('motif');
        $this->assertEqualsWithDelta(95, $this->inventory()->quantity_on_hand, 0.01);
    }

    public function test_a_reception_already_partly_out_is_refused(): void
    {
        $this->actingAs($this->manager)->post(route('stock.manual-entries.store'), [
            'product_id' => $this->engrais->id, 'entry_type' => 'consumption', 'quantity' => 120, 'date' => '2026-10-25',
        ])->assertSessionHasNoErrors();

        $this->annuler($this->wrong())->assertSessionHasErrors('motif');
        $this->assertEqualsWithDelta(30, $this->inventory()->quantity_on_hand, 0.01);
        $this->assertNull($this->wrong()->annulation_id);
    }

    public function test_only_a_manager_of_the_farm_may_cancel_and_with_a_reason(): void
    {
        $this->annuler($this->wrong(), $this->magasinier)->assertForbidden();
        $stranger = User::factory()->create(['role' => 'farm_manager', 'farm_id' => Farm::create(['name' => 'Other'])->id]);
        $this->annuler($this->wrong(), $stranger)->assertForbidden();
        $this->annuler($this->wrong(), $this->manager, '')->assertSessionHasErrors('motif');

        $this->assertNull($this->wrong()->annulation_id);
        $this->assertEqualsWithDelta(150, $this->inventory()->quantity_on_hand, 0.01);
    }

    public function test_a_cancelled_reception_delivered_nothing_in_the_supplier_stats_and_turnover(): void
    {
        $this->annuler($this->wrong());

        $this->actingAs($this->manager)->get(route('stock.suppliers.show', $this->supplier))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total_deliveries', 1)
                ->where('stats.total_value', fn ($v) => abs($v - 1000) < 0.01)
                ->has('supplier.stock_movements', 2));
        $this->actingAs($this->manager)->get(route('stock.reports.stock-turnover'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stockTurnoverData.0.purchases', fn ($q) => abs($q - 100) < 0.01)
                ->where('stockTurnoverData.0.ending_inventory', fn ($q) => abs($q - 100) < 0.01));
    }
}
