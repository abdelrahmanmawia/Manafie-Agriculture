<?php

namespace Tests\Feature\Stock;

use App\Models\Farm;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The whole magasin counted at once: each difference an adjustment dated the count day, at the CUMP. */
class ComptageTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $magasinier;

    private Product $engrais;

    private Product $semence;

    private Product $sac;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25 10:00');

        $this->farm = Farm::create(['name' => 'Test Farm']);
        $this->magasinier = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id]);
        $category = ProductCategory::create(['farm_id' => $this->farm->id, 'name' => 'Engrais']);
        $make = fn ($name) => Product::create(['farm_id' => $this->farm->id, 'name' => $name, 'category_id' => $category->id, 'unit_type' => 'kg', 'min_stock_level' => 0, 'unit_cost' => 99, 'is_active' => true]);
        $this->engrais = $make('Engrais');
        $this->semence = $make('Semence');
        $this->sac = $make('Sac');

        $this->reception($this->engrais, 100, 10, '2026-10-20');
        $this->reception($this->semence, 50, 4, '2026-10-20');
        $this->reception($this->sac, 30, 2, '2026-10-20');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reception(Product $product, float $quantity, float $cost, string $date): void
    {
        $this->actingAs($this->magasinier)->post(route('stock.movements.in'), [
            'product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost, 'date' => $date,
        ])->assertSessionHasNoErrors();
    }

    private function comptage(string $date, array $quantities)
    {
        return $this->actingAs($this->magasinier)->post(route('stock.inventory.comptage.store'), [
            'date' => $date,
            'lignes' => collect($quantities)->map(fn ($q, $id) => ['product_id' => $id, 'counted_quantity' => $q])->values()->all(),
        ]);
    }

    private function onHand(Product $product): float
    {
        return (float) StockInventory::where('product_id', $product->id)->value('quantity_on_hand');
    }

    public function test_each_difference_becomes_an_adjustment_at_the_cump_and_the_rest_is_untouched(): void
    {
        $this->comptage('2026-10-25', [$this->engrais->id => 96, $this->semence->id => 50])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Comptage du 25/10/2026 enregistré : 2 produit(s) compté(s), 1 écart(s), valeur nette -40,00 DH.');

        $this->assertEqualsWithDelta(96, $this->onHand($this->engrais), 0.01);
        $adjustment = StockMovement::where('movement_type', 'adjustment')->sole(); // none for the matching Semence
        $this->assertSame($this->engrais->id, $adjustment->product_id);
        $this->assertEqualsWithDelta(-4, $adjustment->quantity, 0.01);
        $this->assertEqualsWithDelta(-40, $adjustment->total_cost, 0.01); // CUMP 10, not the catalog 99
        $this->assertSame('Comptage de stock. Précédent : 100, Compté : 96', $adjustment->notes);
        $this->assertSame('2026-10-25', StockInventory::where('product_id', $this->semence->id)->first()->last_count_date->toDateString());
        $this->assertEqualsWithDelta(30, $this->onHand($this->sac), 0.01);
        $this->assertNull(StockInventory::where('product_id', $this->sac->id)->value('last_count_date'));
    }

    public function test_a_sheet_counted_yesterday_keeps_the_sorties_made_since(): void
    {
        $this->actingAs($this->magasinier)->post(route('stock.manual-entries.store'), [
            'product_id' => $this->engrais->id, 'entry_type' => 'consumption', 'quantity' => 10, 'date' => '2026-10-25',
        ])->assertSessionHasNoErrors();

        // Yesterday the books said 100 and the shelf held 80; 10 went out today.
        $this->comptage('2026-10-24', [$this->engrais->id => 80])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(70, $this->onHand($this->engrais), 0.01);
        $adjustment = StockMovement::where('movement_type', 'adjustment')->sole();
        $this->assertEqualsWithDelta(-20, $adjustment->quantity, 0.01);
        $this->assertSame('2026-10-24', $adjustment->date->toDateString());
    }

    public function test_a_refused_line_saves_nothing(): void
    {
        $this->comptage('2026-10-25', [$this->sac->id => 28]);

        // Counting Engrais for the 24th is fine, but Sac was already counted on the 25th.
        $this->comptage('2026-10-24', [$this->engrais->id => 90, $this->sac->id => 25])
            ->assertSessionHasErrors('lignes');

        $this->assertEqualsWithDelta(100, $this->onHand($this->engrais), 0.01);
        $this->assertEqualsWithDelta(28, $this->onHand($this->sac), 0.01);
        $this->assertSame(1, StockMovement::where('movement_type', 'adjustment')->count());
    }

    public function test_a_count_that_would_leave_negative_stock_is_refused(): void
    {
        $this->actingAs($this->magasinier)->post(route('stock.manual-entries.store'), [
            'product_id' => $this->sac->id, 'entry_type' => 'consumption', 'quantity' => 30, 'date' => '2026-10-25',
        ]);

        $this->comptage('2026-10-24', [$this->sac->id => 10])->assertSessionHasErrors('lignes');
        $this->assertEqualsWithDelta(0, $this->onHand($this->sac), 0.01);
    }

    public function test_a_future_date_or_another_farms_product_is_refused(): void
    {
        $this->comptage('2026-10-26', [$this->engrais->id => 1])->assertSessionHasErrors('date');

        $other = Farm::create(['name' => 'Other']);
        $foreign = Product::create(['farm_id' => $other->id, 'name' => 'X', 'unit_type' => 'kg', 'min_stock_level' => 0, 'unit_cost' => 1, 'is_active' => true]);
        $this->comptage('2026-10-25', [$this->engrais->id => 1, $foreign->id => 1])->assertForbidden();
        $this->assertEqualsWithDelta(100, $this->onHand($this->engrais), 0.01);
    }

    public function test_the_page_shows_the_books_as_they_stood_on_the_count_day_and_the_sheet_prints(): void
    {
        $this->actingAs($this->magasinier)->get(route('stock.inventory.comptage', ['date' => '2026-10-19']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Inventory/Comptage')
                ->where('date', '2026-10-19')
                ->has('lignes', 3)
                ->where('lignes.0.name', 'Engrais')
                ->where('lignes.0.quantity', 0)); // before the réception of the 20th
        $this->actingAs($this->magasinier)->get(route('stock.inventory.comptage'))
            ->assertInertia(fn (Assert $page) => $page->where('date', '2026-10-25')->where('lignes.0.quantity', 100));

        $this->actingAs($this->magasinier)->get(route('stock.inventory.comptage.pdf', ['aveugle' => 1]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_pointeur_without_stock_access_cannot_count(): void
    {
        $pointeur = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'acces_pointage' => 'saisie', 'acces_stock' => 'aucun']);

        $this->actingAs($pointeur)->get(route('stock.inventory.comptage'))->assertForbidden();
        $this->actingAs($pointeur)->post(route('stock.inventory.comptage.store'), [
            'date' => '2026-10-25', 'lignes' => [['product_id' => $this->engrais->id, 'counted_quantity' => 1]],
        ])->assertForbidden();
    }
}
