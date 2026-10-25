<?php

namespace Tests\Feature\Stock;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Several products taken at once: one sortie per product, the rest shared, all saved or none. */
class SortieMultipleTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $magasinier;

    private Product $engrais;

    private Product $semence;

    private Product $gasoil;

    private Bloc $bloc;

    private Employee $ouvrier;

    private Vehicle $tracteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Test Farm']);
        $this->magasinier = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id]);
        $carburant = ProductCategory::create(['farm_id' => $this->farm->id, 'name' => 'Carburant', 'is_vehicle_related' => true, 'is_carburant' => true]);
        $make = fn ($name, $categoryId = null) => Product::create(['farm_id' => $this->farm->id, 'name' => $name, 'category_id' => $categoryId, 'unit_type' => 'kg', 'min_stock_level' => 0, 'unit_cost' => 1, 'is_active' => true]);
        $this->engrais = $make('Engrais');
        $this->semence = $make('Semence');
        $this->gasoil = $make('Gasoil', $carburant->id);
        foreach ([[$this->engrais, 100, 10], [$this->semence, 40, 5], [$this->gasoil, 500, 12]] as [$product, $quantity, $cost]) {
            $this->actingAs($this->magasinier)->post(route('stock.movements.in'), [
                'product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost, 'date' => '2026-10-20',
            ])->assertSessionHasNoErrors();
        }
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        $division = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'A', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $this->ouvrier = Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => $division->id, 'matricule' => '001', 'full_name' => 'Ouvrier', 'base_rate' => 97.44, 'is_active' => true]);
        $this->tracteur = Vehicle::create(['farm_id' => $this->farm->id, 'name' => 'Tracteur', 'plate_number' => 'T-1', 'type' => 'tractor', 'fuel_type' => 'diesel', 'is_active' => true]);
    }

    private function sortir(array $lignes, array $shared = [])
    {
        return $this->actingAs($this->magasinier)->post(route('stock.manual-entries.store'), $shared + [
            'lignes' => $lignes,
            'entry_type' => 'consumption',
            'employee_id' => $this->ouvrier->id,
            'bloc_id' => $this->bloc->id,
            'date' => '2026-10-25',
            'notes' => 'Traitement B1',
        ]);
    }

    private function onHand(Product $product): float
    {
        return (float) StockInventory::where('product_id', $product->id)->value('quantity_on_hand');
    }

    public function test_each_product_gets_its_own_sortie_sharing_the_rest(): void
    {
        $this->sortir([
            ['product_id' => $this->engrais->id, 'quantity' => 30],
            ['product_id' => $this->semence->id, 'quantity' => 4],
            ['product_id' => $this->gasoil->id, 'quantity' => 60],
        ], ['vehicle_id' => $this->tracteur->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '3 produit(s) sorti(s), dont 1 ravitaillement(s) (dans Carburant).');

        $this->assertSame(2, ManualStockEntry::count());
        foreach (ManualStockEntry::all() as $entry) {
            $this->assertSame([$this->ouvrier->id, $this->bloc->id, '2026-10-25', 'Traitement B1'], [$entry->employee_id, $entry->bloc_id, $entry->date->toDateString(), $entry->notes]);
        }
        $this->assertEqualsWithDelta(70, $this->onHand($this->engrais), 0.01);
        $this->assertEqualsWithDelta(36, $this->onHand($this->semence), 0.01);
        $this->assertEqualsWithDelta(440, $this->onHand($this->gasoil), 0.01);
        $this->assertEqualsWithDelta(300, StockMovement::where('reference_type', 'manual_entry')->where('product_id', $this->engrais->id)->sole()->total_cost, 0.01);
        $this->assertSame($this->tracteur->id, FuelTransaction::sole()->vehicle_id);
    }

    public function test_one_line_over_the_stock_saves_nothing(): void
    {
        $this->sortir([
            ['product_id' => $this->engrais->id, 'quantity' => 30],
            ['product_id' => $this->semence->id, 'quantity' => 41],
        ])->assertSessionHasErrors('lignes.1.quantity');

        $this->assertSame(0, ManualStockEntry::count());
        $this->assertEqualsWithDelta(100, $this->onHand($this->engrais), 0.01);
    }

    public function test_a_product_twice_or_fuel_without_a_vehicle_is_refused(): void
    {
        $this->sortir([
            ['product_id' => $this->engrais->id, 'quantity' => 1],
            ['product_id' => $this->engrais->id, 'quantity' => 2],
        ])->assertSessionHasErrors('lignes.1.product_id');

        $this->sortir([
            ['product_id' => $this->engrais->id, 'quantity' => 1],
            ['product_id' => $this->gasoil->id, 'quantity' => 20],
        ])->assertSessionHasErrors('vehicle_id');

        $this->assertSame(0, ManualStockEntry::count());
        $this->assertSame(0, FuelTransaction::count());
    }

    public function test_another_farms_product_is_forbidden(): void
    {
        $foreign = Product::create(['farm_id' => Farm::create(['name' => 'Other'])->id, 'name' => 'X', 'unit_type' => 'kg', 'min_stock_level' => 0, 'unit_cost' => 1, 'is_active' => true]);

        $this->sortir([
            ['product_id' => $this->engrais->id, 'quantity' => 1],
            ['product_id' => $foreign->id, 'quantity' => 1],
        ])->assertForbidden();
        $this->assertSame(0, ManualStockEntry::count());
    }

    public function test_the_form_shows_what_the_magasin_holds(): void
    {
        $this->actingAs($this->magasinier)->get(route('stock.manual-entries.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.0.name', 'Engrais')
                ->where('products.0.quantity_on_hand', fn ($q) => abs($q - 100) < 0.01));
    }
}
