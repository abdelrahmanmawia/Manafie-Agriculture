<?php

namespace Tests\Feature;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Product;
use App\Models\Quinzaine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The Accueil (what the responsable follows) and the Stock dashboard (the magasinier's day). */
class TableauxDeBordTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $responsable;

    private User $magasinier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-26 09:00');

        $this->farm = Farm::create(['name' => 'Persealand']);
        $this->responsable = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id, 'acces_pointage' => 'consultation', 'acces_stock' => 'consultation']);
        $this->magasinier = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'acces_pointage' => 'aucun', 'acces_stock' => 'saisie']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function produit(string $name, float $min): Product
    {
        return Product::create(['farm_id' => $this->farm->id, 'name' => $name, 'unit_type' => 'kg', 'min_stock_level' => $min, 'unit_cost' => 99, 'is_active' => true]);
    }

    private function reception(Product $product, float $quantity, float $cost): void
    {
        $this->actingAs($this->magasinier)->post(route('stock.movements.in'), [
            'product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost, 'date' => '2026-10-01',
        ])->assertSessionHasNoErrors();
    }

    public function test_the_accueil_shows_today_the_open_period_and_the_stock(): void
    {
        $division = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'A', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $q = Quinzaine::create(['enterprise_id' => $division->id, 'label' => '2QZ', 'start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'is_closed' => false]);
        $op = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Irrigation', 'abbreviation' => 'Irr']);
        $bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        foreach (['001' => true, '002' => false] as $matricule => $avecOperation) {
            $worker = Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => $division->id, 'matricule' => $matricule, 'full_name' => "W{$matricule}", 'base_rate' => 97.44, 'is_active' => true]);
            foreach (['2026-10-25', '2026-10-26'] as $date) {
                PointageRecord::create(['employee_id' => $worker->id, 'quinzaine_id' => $q->id, 'date' => $date, 'present' => true,
                    'operation_id' => $avecOperation ? $op->id : null, 'bloc_id' => $avecOperation ? $bloc->id : null,
                    'hours' => 0, 'rate' => 97.44, 'brut' => 97.44, 'net' => 100]);
            }
        }
        $engrais = $this->produit('Engrais', 50);
        $this->reception($engrais, 100, 10);
        $this->actingAs($this->magasinier)->post(route('stock.manual-entries.store'), [
            'product_id' => $engrais->id, 'entry_type' => 'consumption', 'quantity' => 60, 'date' => '2026-10-20',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->responsable)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/FarmDashboard')
                ->where('pointageDuJour.presents', 2)
                ->where('pointageDuJour.a_preciser_jour', 1)
                ->where('pointageDuJour.jours_periode', 4)
                ->where('pointageDuJour.a_preciser_periode', 2)
                ->where('pointageDuJour.net_periode', fn ($net) => abs($net - 400) < 0.01)
                ->where('stockStats.valeur', fn ($v) => abs($v - 400) < 0.01)       // 40 kg at CUMP 10
                ->where('stockStats.sortiesMois', fn ($v) => abs($v - 600) < 0.01)  // 60 kg at 10
                ->where('stockStats.lowStock', 1));
    }

    public function test_the_stock_dashboard_lists_what_to_reorder_furthest_under_first(): void
    {
        $peu = $this->produit('Un peu bas', 10);
        $tres = $this->produit('Très bas', 100);
        $sansMin = $this->produit('Sans minimum', 0);
        $this->reception($peu, 8, 5);
        $this->reception($tres, 5, 5);
        $this->reception($sansMin, 1, 5);

        $this->actingAs($this->magasinier)->get(route('stock.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lowStockList', 2)
                ->where('lowStockList.0.name', 'Très bas')
                ->where('lowStockList.1.name', 'Un peu bas')
                ->where('stats.pleinsAVerifier', 0));
    }
}
