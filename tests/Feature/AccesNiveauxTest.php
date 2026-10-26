<?php

namespace Tests\Feature;

use App\Models\Bloc;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\ManualStockEntry;
use App\Models\Operation;
use App\Models\Product;
use App\Models\StockInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an account can do is its level on each domain — aucun, consultation, saisie — not its
 * role: the pointeur runs Pointage, the magasinier Stock, the responsable only consults.
 */
class AccesNiveauxTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $division;

    private User $pointeur;

    private User $magasinier;

    private User $responsable;

    private Product $engrais;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Persealand']);
        $this->division = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'A', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $account = fn (string $role, string $pointage, string $stock) => User::factory()->create([
            'role' => $role, 'farm_id' => $this->farm->id, 'acces_pointage' => $pointage, 'acces_stock' => $stock,
        ]);
        $this->pointeur = $account('data_entry', 'saisie', 'aucun');
        $this->magasinier = $account('data_entry', 'aucun', 'saisie');
        $this->responsable = $account('farm_manager', 'consultation', 'consultation');

        $this->engrais = Product::create(['farm_id' => $this->farm->id, 'name' => 'Engrais', 'unit_type' => 'kg', 'min_stock_level' => 0, 'unit_cost' => 10, 'is_active' => true]);
        $this->actingAs($this->magasinier)->post(route('stock.movements.in'), [
            'product_id' => $this->engrais->id, 'quantity' => 100, 'unit_cost' => 10, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
    }

    private function nouvellePeriode(User $user)
    {
        return $this->actingAs($user)->post(route('settings.quinzaine'), [
            'enterprise_ids' => [$this->division->id], 'label' => '1QZ', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15',
        ]);
    }

    private function sortie(User $user)
    {
        return $this->actingAs($user)->post(route('stock.manual-entries.store'), [
            'product_id' => $this->engrais->id, 'entry_type' => 'consumption', 'quantity' => 5, 'date' => now()->toDateString(),
        ]);
    }

    public function test_the_responsable_consults_both_domains_and_changes_nothing(): void
    {
        $this->actingAs($this->responsable)->get(route('pointage.quinzaines'))->assertOk();
        $this->actingAs($this->responsable)->get(route('stock.inventory.index'))->assertOk();
        $this->actingAs($this->responsable)->get(route('stock.reports.cost-per-vehicle'))->assertOk();

        $this->nouvellePeriode($this->responsable)->assertSessionHas('error', 'Accès en consultation : vous ne pouvez pas modifier le pointage.');
        $this->sortie($this->responsable)->assertSessionHas('error', 'Accès en consultation : vous ne pouvez pas modifier le stock.');
        $this->actingAs($this->responsable)->patch(route('farms.updateSettings', $this->farm), ['name' => 'X', 'box_weight_kg' => 10])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('quinzaines', 0);
        $this->assertSame(0, ManualStockEntry::count());
        $this->assertSame('Persealand', $this->farm->fresh()->name);
    }

    public function test_the_pointeur_runs_pointage_and_its_settings_but_never_sees_stock(): void
    {
        $this->nouvellePeriode($this->pointeur)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('quinzaines', 1);
        $this->actingAs($this->pointeur)->post(route('farms.blocs.store', $this->farm), ['name' => 'B9'])->assertSessionHasNoErrors();
        $this->assertTrue(Bloc::where('name', 'B9')->exists());
        $this->actingAs($this->pointeur)->post(route('farms.operations.store', $this->farm), ['name' => 'Taille', 'abbreviation' => 'T'])->assertSessionHasNoErrors();
        $this->assertTrue(Operation::where('name', 'Taille')->exists());

        $this->actingAs($this->pointeur)->get(route('stock.inventory.index'))->assertForbidden();
        $this->sortie($this->pointeur)->assertForbidden();
        $this->actingAs($this->pointeur)->get(route('dashboard'))->assertRedirect(route('pointage.today'));
    }

    public function test_the_magasinier_runs_stock_without_any_check_and_never_sees_pointage(): void
    {
        $this->sortie($this->magasinier)->assertSessionHasNoErrors();
        $entry = ManualStockEntry::sole();

        // No verification any more: a sortie stays correctable and deletable by the magasinier.
        $this->actingAs($this->magasinier)->put(route('stock.manual-entries.update', $entry), ['quantity' => 8])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(92, StockInventory::where('product_id', $this->engrais->id)->value('quantity_on_hand'), 0.01);
        $this->actingAs($this->magasinier)->delete(route('stock.manual-entries.destroy', $entry))->assertSessionHas('success');
        $this->assertEqualsWithDelta(100, StockInventory::where('product_id', $this->engrais->id)->value('quantity_on_hand'), 0.01);

        $this->actingAs($this->magasinier)->get(route('pointage.quinzaines'))->assertForbidden();
        $this->actingAs($this->magasinier)->get(route('farms.settings', $this->farm))->assertForbidden();
        $this->actingAs($this->magasinier)->get(route('dashboard'))->assertRedirect(route('stock.dashboard'));
    }

    public function test_a_consultation_account_cannot_point_through_the_scan_station(): void
    {
        $token = $this->responsable->createToken('scan-station', ["farm:{$this->farm->id}"], now()->addDay())->plainTextToken;
        $this->withToken($token)->getJson('/api/pointage/scan-station/station-data')->assertForbidden();
    }

    public function test_the_admin_sets_each_domains_level(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->put(route('users.update', $this->responsable), [
            'name' => 'Responsable', 'email' => $this->responsable->email, 'role' => 'farm_manager', 'farm_id' => $this->farm->id,
            'acces_pointage' => 'saisie', 'acces_stock' => 'consultation',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->responsable->fresh()->peutSaisir('pointage'));
        $this->assertFalse($this->responsable->fresh()->peutSaisir('stock'));

        $this->actingAs($admin)->put(route('users.update', $this->responsable), [
            'name' => 'Responsable', 'email' => $this->responsable->email, 'role' => 'farm_manager', 'farm_id' => $this->farm->id,
            'acces_pointage' => 'tout', 'acces_stock' => 'consultation',
        ])->assertSessionHasErrors('acces_pointage');
    }

    public function test_only_the_pointeur_may_manage_the_equipes(): void
    {
        $this->actingAs($this->responsable)->get(route('equipes.index'))->assertInertia(fn ($page) => $page->where('canManage', false));
        $this->actingAs($this->pointeur)->get(route('equipes.index'))->assertInertia(fn ($page) => $page->where('canManage', true));
    }
}
