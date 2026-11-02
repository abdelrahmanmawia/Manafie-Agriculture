<?php

namespace Tests\Feature;

use App\Exports\ChargesExternesExport;
use App\Models\ChargeExterne;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Prestataire;
use App\Models\Quinzaine;
use App\Models\User;
use App\Services\ChargeExterneService;
use App\Services\CoutFermeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Companies under contract: a charge per period, its checks, the year's follow-up, the farm's cost. */
class ChargeExterneTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $pointeur;

    private Enterprise $division;

    private Quinzaine $octobre;

    private Prestataire $gardes;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00');

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->pointeur = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'acces_pointage' => 'saisie']);
        $this->division = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'D', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44, 'invoiced_to_client' => false]);
        $this->octobre = Quinzaine::create(['enterprise_id' => $this->division->id, 'label' => 'Q', 'start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'is_closed' => false]);
        $this->gardes = Prestataire::create(['farm_id' => $this->farm->id, 'name' => 'GARDE SARL', 'service' => 'Gardiennage', 'mode_facturation' => 'par_jour',
            'prix_nuit' => 100, 'prix_jour' => 80, 'effectif_nuit' => 17, 'effectif_jour' => 4, 'contrat_debut' => '2026-01-01', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function saisir(array $data = [])
    {
        return $this->actingAs($this->pointeur)->post(route('pointage.charges.store'), $data + [
            'prestataire_id' => $this->gardes->id, 'start_date' => '2026-10-16', 'end_date' => '2026-10-31',
            'jours_nuit' => 271, 'jours_jour' => 56, 'statut_facture' => 'recue',
        ]);
    }

    private function charge(string $start, string $end, float $montant, array $extra = []): ChargeExterne
    {
        return ChargeExterne::create($extra + ['farm_id' => $this->farm->id, 'prestataire_id' => $this->gardes->id, 'start_date' => $start, 'end_date' => $end, 'jours_nuit' => 10, 'jours_jour' => 5, 'montant' => $montant, 'statut_facture' => 'payee']);
    }

    private function suivi(array $filtres = []): array
    {
        return json_decode(json_encode(app(ChargeExterneService::class)->suivi($this->farm->id, $filtres)), true);
    }

    public function test_the_amount_comes_from_the_contract_and_the_checks_from_its_headcount(): void
    {
        $this->saisir()->assertSessionHasNoErrors();

        $ligne = $this->suivi()['lignes'][0];
        $this->assertEqualsWithDelta(271 * 100 + 56 * 80, $ligne['montant'], 0.01);
        $this->assertSame(0.0, (float) $ligne['ecart_montant']);
        // 17 people × 16 days, 4 × 16.
        $this->assertSame(272, $ligne['attendu_nuit']);
        $this->assertSame(-1, $ligne['ecart_nuit']);
        $this->assertSame(64, $ligne['attendu_jour']);
        $this->assertSame(-8, $ligne['ecart_jour']);
        $this->assertSame('2QZ Octobre 2026', $ligne['periode']);
    }

    public function test_the_invoices_amount_can_differ_and_shows_the_gap(): void
    {
        $this->saisir(['montant' => 32000])->assertSessionHasNoErrors();

        $ligne = $this->suivi()['lignes'][0];
        $this->assertEqualsWithDelta(32000, $ligne['montant'], 0.01);
        $this->assertEqualsWithDelta(32000 - 31580, $ligne['ecart_montant'], 0.01);
    }

    public function test_a_flat_contract_and_one_with_no_price(): void
    {
        $forfait = Prestataire::create(['farm_id' => $this->farm->id, 'name' => 'FORFAIT', 'service' => 'Nettoyage', 'mode_facturation' => 'forfait', 'forfait' => 5000, 'is_active' => true]);
        $this->saisir(['prestataire_id' => $forfait->id, 'jours_nuit' => 0, 'jours_jour' => 0])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(5000, ChargeExterne::where('prestataire_id', $forfait->id)->sole()->montant, 0.01);

        $sansPrix = Prestataire::create(['farm_id' => $this->farm->id, 'name' => 'SANS PRIX', 'service' => 'Gardiennage', 'mode_facturation' => 'par_jour', 'is_active' => true]);
        $this->saisir(['prestataire_id' => $sansPrix->id])->assertSessionHasErrors('montant');
        $this->saisir(['prestataire_id' => $sansPrix->id, 'montant' => 1000])->assertSessionHasNoErrors();
    }

    public function test_no_days_and_no_amount_is_no_charge(): void
    {
        $this->saisir(['jours_nuit' => '', 'jours_jour' => ''])->assertSessionHasErrors('jours_nuit');
        $this->assertSame(0, ChargeExterne::count());

        // The invoice's amount alone is enough.
        $this->saisir(['jours_nuit' => '', 'jours_jour' => '', 'montant' => 2000])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(2000, ChargeExterne::sole()->montant, 0.01);
    }

    public function test_a_new_price_doesnt_rewrite_charges_already_entered(): void
    {
        $this->saisir();
        $this->gardes->update(['prix_nuit' => 200, 'effectif_nuit' => 30]);
        $charge = ChargeExterne::sole();

        // Saved again with no amount typed, it's priced at the terms it keeps.
        $this->actingAs($this->pointeur)->put(route('pointage.charges.update', $charge), ['start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'jours_nuit' => 270, 'jours_jour' => 56, 'statut_facture' => 'payee'])
            ->assertSessionHasNoErrors();

        $ligne = $this->suivi()['lignes'][0];
        $this->assertEqualsWithDelta(270 * 100 + 56 * 80, $ligne['montant'], 0.01);
        $this->assertSame(272, $ligne['attendu_nuit']);
        $this->assertSame('payee', $ligne['statut_facture']);
    }

    public function test_a_companys_charges_dont_overlap_or_exceed_a_month(): void
    {
        $this->saisir()->assertSessionHasNoErrors();
        $this->saisir(['start_date' => '2026-10-20', 'end_date' => '2026-10-25'])->assertSessionHasErrors('start_date');
        $this->saisir(['start_date' => '2026-11-01', 'end_date' => '2026-12-15'])->assertSessionHasErrors('end_date');
        $this->assertSame(1, ChargeExterne::count());

        // Another company on the same dates is fine.
        $autre = Prestataire::create(['farm_id' => $this->farm->id, 'name' => 'AUTRE', 'service' => 'Nettoyage', 'mode_facturation' => 'par_jour', 'prix_jour' => 10, 'is_active' => true]);
        $this->saisir(['prestataire_id' => $autre->id, 'jours_nuit' => 0])->assertSessionHasNoErrors();
    }

    public function test_the_year_totals_and_the_year_before_over_the_same_dates(): void
    {
        $this->charge('2026-01-01', '2026-01-15', 1000);
        $this->charge('2026-10-16', '2026-10-31', 3000);
        $this->charge('2025-01-01', '2025-01-15', 500);
        $this->charge('2025-10-16', '2025-10-31', 700);
        $this->charge('2025-12-01', '2025-12-15', 9000); // after this date last year: left out

        $s = $this->suivi(['annee' => 2026]);
        $this->assertEqualsWithDelta(4000, $s['totaux']['montant'], 0.01);
        $this->assertSame(20, $s['totaux']['jours_nuit']);
        $this->assertSame(10, $s['totaux']['jours_jour']);
        $this->assertEqualsWithDelta(4000 / 30, $s['totaux']['cout_jour'], 0.01);
        $this->assertEqualsWithDelta(1200, $s['precedente']['montant'], 0.01);
        $this->assertSame('2025-11-02', $s['precedente']['jusquau']);
        $this->assertSame([2026, 2025], $s['annees']);

        // A past year compares with the whole year before it.
        $this->assertEqualsWithDelta(10200, $this->suivi(['annee' => 2025])['totaux']['montant'], 0.01);
        $this->assertNull($this->suivi(['annee' => 2025])['precedente']);
    }

    public function test_filters_narrow_the_follow_up(): void
    {
        $autre = Prestataire::create(['farm_id' => $this->farm->id, 'name' => 'AUTRE', 'service' => 'Nettoyage', 'mode_facturation' => 'forfait', 'forfait' => 10, 'is_active' => true]);
        $this->charge('2026-10-16', '2026-10-31', 3000);
        $this->charge('2026-10-16', '2026-10-31', 100, ['prestataire_id' => $autre->id]);

        $this->assertEqualsWithDelta(3100, $this->suivi()['totaux']['montant'], 0.01);
        $this->assertCount(2, $this->suivi()['parPrestataire']);
        $this->assertEqualsWithDelta(100, $this->suivi(['prestataire_id' => $autre->id])['totaux']['montant'], 0.01);
        $this->assertEqualsWithDelta(3000, $this->suivi(['service' => 'Gardiennage'])['totaux']['montant'], 0.01);
    }

    public function test_a_finished_period_with_nothing_entered_is_flagged_within_the_contract(): void
    {
        Quinzaine::create(['enterprise_id' => $this->division->id, 'label' => 'N', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15', 'is_closed' => false]);

        // October is over and missing; November is running.
        $this->assertSame(['2026-10-16_2026-10-31'], array_column($this->suivi()['manquantes'], 'periode'));

        // Not before the contract, nor after it, nor for a company no longer active.
        $this->gardes->update(['contrat_debut' => '2026-11-01']);
        $this->assertSame([], $this->suivi()['manquantes']);
        $this->gardes->update(['contrat_debut' => '2026-01-01', 'contrat_fin' => '2026-10-10']);
        $this->assertSame([], $this->suivi()['manquantes']);
        $this->gardes->update(['contrat_fin' => null, 'is_active' => false]);
        $this->assertSame([], $this->suivi()['manquantes']);

        // A charge covering it, on other dates too, is enough.
        $this->gardes->update(['is_active' => true]);
        $this->charge('2026-10-01', '2026-10-31', 1000);
        $this->assertSame([], $this->suivi()['manquantes']);
    }

    public function test_a_contract_ending_soon_or_over_is_flagged(): void
    {
        $this->assertSame([], $this->suivi()['contrats']);

        $this->gardes->update(['contrat_fin' => '2027-06-30']);
        $this->assertSame([], $this->suivi()['contrats']);

        $this->gardes->update(['contrat_fin' => '2026-11-20']);
        $this->assertFalse($this->suivi()['contrats'][0]['expire']);

        $this->gardes->update(['contrat_fin' => '2026-10-20']);
        $this->assertTrue($this->suivi()['contrats'][0]['expire']);
    }

    public function test_a_charge_adds_to_its_periods_cost_for_the_farm_and_not_to_the_workers(): void
    {
        $periode = fn () => app(CoutFermeService::class)->parPeriode(Quinzaine::with('enterprise')->get(), $this->farm->id)['2026-10-16_2026-10-31'];

        $this->assertSame('GARDE SARL', $periode()['externes_manquantes'][0]['prestataire']);
        $this->assertEquals(0, $periode()['total']);

        $this->charge('2026-10-16', '2026-10-31', 3000);
        $this->assertEqualsWithDelta(3000, $periode()['externes'], 0.01);
        $this->assertEqualsWithDelta(3000, $periode()['total'], 0.01);
        $this->assertEquals(0, $periode()['total_hors_externes']);
        $this->assertEquals(0, $periode()['net']);
        $this->assertSame([], $periode()['externes_manquantes']);

        // On the page, whether the period is open or closed: the invoice often comes later.
        $this->octobre->update(['is_closed' => true]);
        $this->actingAs($this->pointeur)->get(route('pointage.quinzaines'))->assertInertia(fn (Assert $page) => $page
            ->where('coutsPeriodes.2026-10-16_2026-10-31.externes', fn ($v) => abs($v - 3000) < 0.01));
        $charge = ChargeExterne::sole();
        $this->actingAs($this->pointeur)->put(route('pointage.charges.update', $charge), ['start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'jours_nuit' => 10, 'jours_jour' => 5, 'montant' => 3500, 'statut_facture' => 'recue'])
            ->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(3500, $periode()['externes'], 0.01);
    }

    public function test_the_analyses_show_it_apart_from_the_figures_against_production(): void
    {
        $this->charge('2026-10-16', '2026-10-31', 3000);

        $this->actingAs($this->pointeur)->get(route('analytics.index'))->assertInertia(fn (Assert $page) => $page
            ->where('cout.externes', fn ($v) => abs($v - 3000) < 0.01)
            ->where('cout.total_ferme', fn ($v) => abs($v - 3000) < 0.01)
            ->where('cout.total', 0));
    }

    public function test_the_export_follows_the_page(): void
    {
        $this->charge('2026-10-16', '2026-10-31', 3000, ['facture_numero' => 'F-12']);
        $this->charge('2026-01-01', '2026-01-15', 1000);
        Quinzaine::create(['enterprise_id' => $this->division->id, 'label' => 'S', 'start_date' => '2026-09-16', 'end_date' => '2026-09-30', 'is_closed' => false]);

        $path = tempnam(sys_get_temp_dir(), 'ch');
        file_put_contents($path, Excel::raw(new ChargesExternesExport(app(ChargeExterneService::class)->suivi($this->farm->id, ['annee' => 2026])), ExcelType::XLSX));
        $ws = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $this->assertSame('1QZ Janvier 2026', $ws->getCell('C4')->getValue());
        $this->assertSame('F-12', $ws->getCell('M5')->getValue());
        $this->assertSame('TOTAL', $ws->getCell('A6')->getValue());
        $this->assertEqualsWithDelta(4000, $ws->getCell('J6')->getCalculatedValue(), 0.01);
        $this->assertSame(20, (int) $ws->getCell('D6')->getCalculatedValue());
        // What's missing follows: the total isn't read as complete.
        $this->assertStringContainsString('NON SAISI', $ws->getCell('A8')->getValue());
        $this->assertSame('2QZ Septembre 2026', $ws->getCell('C9')->getValue());

        $this->actingAs($this->pointeur)->get(route('pointage.charges.export', ['annee' => 2026]))->assertOk()->assertDownload('Charges_externes_2026.xlsx');
    }

    public function test_the_companys_file_is_private_and_goes_with_its_charge(): void
    {
        Storage::fake('local');

        $this->saisir(['document' => UploadedFile::fake()->create('pointage.xlsx', 20)])->assertSessionHasNoErrors();
        $charge = ChargeExterne::sole();
        $this->assertSame('pointage.xlsx', $charge->document_name);
        Storage::disk('local')->assertExists($charge->document_path);

        $this->actingAs($this->pointeur)->get(route('pointage.charges.document', $charge))->assertOk()->assertDownload('pointage.xlsx');

        // Replaced, then removed with the charge.
        $ancien = $charge->document_path;
        $this->actingAs($this->pointeur)->put(route('pointage.charges.update', $charge), ['start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'jours_nuit' => 271, 'jours_jour' => 56, 'statut_facture' => 'recue', 'document' => UploadedFile::fake()->create('nouveau.pdf', 20)])
            ->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($ancien);
        $nouveau = $charge->fresh()->document_path;
        $this->assertSame('nouveau.pdf', $charge->fresh()->document_name);

        $this->actingAs($this->pointeur)->delete(route('pointage.charges.destroy', $charge))->assertSessionHas('success');
        Storage::disk('local')->assertMissing($nouveau);

        $this->saisir(['document' => UploadedFile::fake()->create('script.php', 1)])->assertSessionHasErrors('document');
    }

    public function test_a_company_with_charges_is_deactivated_not_deleted(): void
    {
        $this->charge('2026-10-16', '2026-10-31', 3000);

        $this->actingAs($this->pointeur)->delete(route('pointage.prestataires.destroy', $this->gardes))->assertSessionHasErrors('prestataire');
        $this->assertDatabaseHas('prestataires', ['id' => $this->gardes->id]);

        $vide = Prestataire::create(['farm_id' => $this->farm->id, 'name' => 'VIDE', 'service' => 'X', 'mode_facturation' => 'par_jour', 'is_active' => true]);
        $this->actingAs($this->pointeur)->delete(route('pointage.prestataires.destroy', $vide))->assertSessionHas('success');
        $this->assertDatabaseMissing('prestataires', ['id' => $vide->id]);
    }

    public function test_the_contract_is_managed_from_the_page(): void
    {
        $this->actingAs($this->pointeur)->post(route('pointage.prestataires.store'), ['name' => 'NOUVELLE', 'service' => 'Gardiennage', 'mode_facturation' => 'par_jour', 'prix_nuit' => 120, 'effectif_nuit' => 5])
            ->assertSessionHasNoErrors();
        $p = Prestataire::where('name', 'NOUVELLE')->sole();
        $this->assertSame($this->farm->id, $p->farm_id);
        $this->assertTrue($p->is_active);

        $this->actingAs($this->pointeur)->post(route('pointage.prestataires.store'), ['name' => 'NOUVELLE', 'service' => 'X', 'mode_facturation' => 'par_jour'])->assertSessionHasErrors('name');

        $this->actingAs($this->pointeur)->put(route('pointage.prestataires.update', $p), ['name' => 'NOUVELLE', 'service' => 'Gardiennage', 'mode_facturation' => 'forfait', 'forfait' => 9000, 'is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($p->fresh()->is_active);
        $this->assertEqualsWithDelta(9000, $p->fresh()->forfait, 0.01);
    }

    public function test_who_can_see_and_change_them(): void
    {
        $charge = $this->charge('2026-10-16', '2026-10-31', 3000);

        // Consulting: read and export, no change.
        $responsable = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id, 'acces_pointage' => 'consultation']);
        $this->actingAs($responsable)->get(route('pointage.charges.index'))->assertOk();
        $this->actingAs($responsable)->get(route('pointage.charges.export'))->assertOk();
        $this->actingAs($responsable)->delete(route('pointage.charges.destroy', $charge));
        $this->assertDatabaseHas('charges_externes', ['id' => $charge->id]);

        // Farm-wide: not for a division-locked user.
        $verrouille = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'enterprise_id' => $this->division->id, 'acces_pointage' => 'saisie']);
        $this->actingAs($verrouille)->get(route('pointage.charges.index'))->assertForbidden();
        $this->actingAs($verrouille)->post(route('pointage.charges.store'), ['prestataire_id' => $this->gardes->id])->assertForbidden();

        // Another farm's: refused.
        $autre = Farm::create(['name' => 'Autre']);
        $etranger = User::factory()->create(['role' => 'data_entry', 'farm_id' => $autre->id, 'acces_pointage' => 'saisie']);
        $this->actingAs($etranger)->get(route('pointage.charges.document', $charge))->assertForbidden();
        $this->actingAs($etranger)->delete(route('pointage.charges.destroy', $charge))->assertForbidden();
        $this->actingAs($etranger)->put(route('pointage.prestataires.update', $this->gardes), ['name' => 'X', 'service' => 'Y', 'mode_facturation' => 'par_jour'])->assertForbidden();
        $this->actingAs($etranger)->post(route('pointage.charges.store'), ['prestataire_id' => $this->gardes->id, 'start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'statut_facture' => 'recue'])
            ->assertSessionHasErrors('prestataire_id');
        $this->actingAs($etranger)->get(route('pointage.charges.index', ['prestataire_id' => $this->gardes->id]))->assertSessionHasErrors('prestataire_id');
    }

    public function test_the_page_renders_with_a_period_to_enter(): void
    {
        $this->actingAs($this->pointeur)->get(route('pointage.charges.index', ['ajouter' => '2026-10-16_2026-10-31', 'annee' => 2026]))
            ->assertInertia(fn (Assert $page) => $page->component('Pointage/ChargesExternes')
                ->where('ajouter', '2026-10-16_2026-10-31')
                ->where('suivi.annee', 2026)
                ->where('periodes.0.label', '2QZ Octobre 2026')
                ->where('suivi.manquantes.0.periode', '2026-10-16_2026-10-31'));
    }
}
