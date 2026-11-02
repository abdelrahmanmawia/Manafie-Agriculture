<?php

namespace Tests\Feature;

use App\Exports\PointageExport;
use App\Exports\TransportSheetExport;
use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\QuinzaineSummary;
use App\Models\TransportCompany;
use App\Models\TransportSnapshot;
use App\Models\TransportVehicle;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\TransportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Closing a period, what stays frozen once it's closed, reopening it, and moving its dates. */
class ClotureQuinzaineTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $manager;

    private Enterprise $interim;

    private Enterprise $direct;

    private Quinzaine $qi;

    private Quinzaine $qd;

    private Employee $ouvrier;

    private Employee $seulementHs;

    private TransportVehicle $bus;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00');

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->interim = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'INTERIM', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44, 'invoiced_to_client' => true]);
        $this->direct = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'DIRECT', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44, 'invoiced_to_client' => false]);
        $this->qi = $this->periode($this->interim, '2026-10-16', '2026-10-31');
        $this->qd = $this->periode($this->direct, '2026-10-16', '2026-10-31');

        $company = TransportCompany::create(['farm_id' => $this->farm->id, 'name' => 'hafilaty', 'is_active' => true]);
        $this->bus = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $company->id, 'code' => 'TRANS 2', 'price_per_person' => 47, 'is_active' => true]);
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'T2', 'transport_vehicle_id' => $this->bus->id, 'is_active' => true]);

        $this->ouvrier = $this->worker('001', $this->interim, $equipe);
        $this->seulementHs = $this->worker('002', $this->interim, $equipe);
        $this->jour($this->ouvrier, $this->qi, '2026-10-20');
        $this->jour($this->ouvrier, $this->qi, '2026-10-21');
        // No plain day: the fiche has to read their NET/J from what this day paid.
        $this->jour($this->seulementHs, $this->qi, '2026-10-20', 2);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function periode(Enterprise $e, string $start, string $end, bool $closed = false): Quinzaine
    {
        return Quinzaine::create(['enterprise_id' => $e->id, 'label' => "Q {$start}", 'start_date' => $start, 'end_date' => $end, 'is_closed' => $closed]);
    }

    private function worker(string $m, Enterprise $e, ?Equipe $equipe = null, array $extra = []): Employee
    {
        return Employee::create($extra + ['farm_id' => $this->farm->id, 'enterprise_id' => $e->id, 'matricule' => $m, 'full_name' => "W{$m}", 'base_rate' => 97.44, 'complement' => 9.13, 'is_active' => true, 'equipe_id' => $equipe?->id]);
    }

    private function jour(Employee $e, Quinzaine $q, string $date, float $hs = 0, bool $operation = true): PointageRecord
    {
        $op = Operation::firstOrCreate(['farm_id' => $this->farm->id, 'name' => 'Irrigation'], ['abbreviation' => 'Irr']);
        $bloc = Bloc::firstOrCreate(['farm_id' => $this->farm->id, 'name' => 'B1']);
        $calc = app(PayrollService::class)->calculate('avec_contrat', 97.44, $hs, (float) $e->complement);

        return PointageRecord::create(['employee_id' => $e->id, 'quinzaine_id' => $q->id, 'date' => $date, 'present' => true,
            'operation_id' => $operation ? $op->id : null, 'bloc_id' => $operation ? $bloc->id : null, 'hours' => $hs, 'rate' => 97.44, 'brut' => 97.44, 'net' => round($calc['total_net'], 2),
            'transport_vehicle_id' => $e->equipe_id ? $this->bus->id : null]);
    }

    /** A cell of a workbook as Excel computes it. */
    private function cellule($export, string $cell)
    {
        $path = tempnam(sys_get_temp_dir(), 'qz');
        file_put_contents($path, Excel::raw($export, ExcelType::XLSX));
        $value = IOFactory::load($path)->getActiveSheet()->getCell($cell)->getCalculatedValue();
        unlink($path);

        return $value;
    }

    private function laSomme(): float
    {
        return (float) $this->cellule(new PointageExport($this->interim->fresh(), $this->qi->start_date, $this->qi->end_date, $this->qi->fresh()), 'C3');
    }

    private function ttcDeLaPage(): float
    {
        $ttc = null;
        $this->actingAs($this->manager)->get(route('pointage.quinzaines'))->assertInertia(function (Assert $page) use (&$ttc) {
            $ttc = collect($page->toArray()['props']['quinzaines'])->firstWhere('id', $this->qi->id)['stats']['ttc'];
        });

        return (float) $ttc;
    }

    private function fermer(): void
    {
        foreach ([$this->qi, $this->qd] as $q) {
            $this->actingAs($this->manager)->post(route('settings.quinzaine.close', $q))->assertSessionHasNoErrors();
        }
    }

    // --- Closing ---

    public function test_a_period_not_over_only_closes_on_an_explicit_confirmation(): void
    {
        $courante = $this->periode($this->interim, '2026-11-01', '2026-11-15');
        $autre = $this->periode($this->direct, '2026-11-01', '2026-11-15');

        foreach (['settings.quinzaine.close', 'settings.quinzaine.close-periode'] as $route) {
            $this->actingAs($this->manager)->post(route($route, $courante))->assertSessionHasErrors('quinzaine');
            $this->assertFalse($courante->fresh()->is_closed);
            $this->assertFalse($autre->fresh()->is_closed);
        }

        $this->actingAs($this->manager)->post(route('settings.quinzaine.close-periode', $courante), ['avant_fin' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($courante->fresh()->is_closed);
        $this->assertTrue($autre->fresh()->is_closed);
    }

    public function test_its_last_day_is_still_not_over_and_the_day_after_is(): void
    {
        $q = $this->periode($this->interim, '2026-11-01', '2026-11-02');
        $this->actingAs($this->manager)->post(route('settings.quinzaine.close', $q))->assertSessionHasErrors('quinzaine');

        Carbon::setTestNow('2026-11-03 09:00');
        $this->actingAs($this->manager)->post(route('settings.quinzaine.close', $q))->assertSessionHasNoErrors();
        $this->assertTrue($q->fresh()->is_closed);
    }

    public function test_closing_the_whole_period_is_all_or_nothing_even_when_a_snapshot_fails(): void
    {
        // The first division's close goes through, the second's snapshot fails.
        $appels = 0;
        $this->mock(TransportService::class, function ($mock) use (&$appels) {
            $mock->shouldReceive('generateSnapshot')->andReturnUsing(function () use (&$appels) {
                if (++$appels === 2) {
                    throw new \RuntimeException('boom');
                }
            });
        });

        $this->actingAs($this->manager)->post(route('settings.quinzaine.close-periode', $this->qi))->assertStatus(500);

        $this->assertSame(2, $appels);
        $this->assertFalse($this->qi->fresh()->is_closed);
        $this->assertFalse($this->qd->fresh()->is_closed);
        $this->assertDatabaseCount('quinzaine_summaries', 0);
    }

    public function test_a_day_a_preciser_in_one_division_keeps_the_others_open_too(): void
    {
        $w = $this->worker('003', $this->direct);
        $this->jour($w, $this->qd, '2026-10-20', 0, false);

        $this->actingAs($this->manager)->post(route('settings.quinzaine.close-periode', $this->qi))->assertSessionHasErrors('quinzaine');

        $this->assertFalse($this->qi->fresh()->is_closed);
        $this->assertDatabaseCount('quinzaine_summaries', 0);
    }

    // --- Frozen once closed ---

    public function test_a_closed_periods_invoice_and_fiche_dont_follow_todays_complement_or_rate(): void
    {
        $this->fermer();
        [$ttc, $somme] = [$this->ttcDeLaPage(), $this->laSomme()];
        $this->assertGreaterThan(0, $ttc);
        $this->assertEqualsWithDelta(QuinzaineSummary::where('quinzaine_id', $this->qi->id)->value('total_ttc'), $ttc, 0.01);
        $this->assertEqualsWithDelta($somme, $ttc, 0.01);

        Employee::query()->update(['complement' => 50]);
        $this->interim->update(['default_brut_rate' => 120]);

        $this->assertEqualsWithDelta($ttc, $this->ttcDeLaPage(), 0.001);
        $this->assertEqualsWithDelta($somme, $this->laSomme(), 0.001);
    }

    public function test_a_worker_on_a_salary_since_doesnt_change_a_closed_fiche(): void
    {
        $this->fermer();
        $somme = $this->laSomme();

        Employee::whereKey($this->ouvrier->id)->update(['salaire_mensuel' => 3500]);

        $this->assertEqualsWithDelta($somme, $this->laSomme(), 0.001);
    }

    public function test_a_closed_periods_transport_sheet_keeps_the_prices_it_closed_at(): void
    {
        $feuille = fn () => (float) $this->cellule(new TransportSheetExport($this->farm, $this->qi->start_date->copy(), $this->qi->end_date->copy()), 'C3');
        $this->assertEqualsWithDelta(3 * 47, $feuille(), 0.01);

        // Partly closed: still live, today's price.
        $this->actingAs($this->manager)->post(route('settings.quinzaine.close', $this->qi));
        TransportVehicle::query()->update(['price_per_person' => 50]);
        $this->assertEqualsWithDelta(3 * 50, $feuille(), 0.01);

        // All closed: frozen at the price of the last close, whatever happens to it next.
        $this->actingAs($this->manager)->post(route('settings.quinzaine.close', $this->qd));
        TransportVehicle::query()->update(['price_per_person' => 100]);
        $this->assertEqualsWithDelta(3 * 50, $feuille(), 0.01);
    }

    // --- Reopening ---

    public function test_a_super_admin_reopens_a_period_and_the_next_close_freezes_it_again(): void
    {
        $this->fermer();
        $this->assertSame(1, QuinzaineSummary::where('quinzaine_id', $this->qi->id)->count());
        $this->assertGreaterThan(0, TransportSnapshot::count());

        $admin = User::factory()->create(['role' => 'super_admin', 'farm_id' => null, 'enterprise_id' => null]);
        $this->actingAs($admin)->withSession(['active_farm_id' => $this->farm->id])
            ->post(route('settings.quinzaine.reopen', $this->qi))->assertSessionHas('success');

        $this->assertFalse($this->qi->fresh()->is_closed);
        $this->assertTrue($this->qd->fresh()->is_closed);
        $this->assertSame(0, QuinzaineSummary::where('quinzaine_id', $this->qi->id)->count());
        $this->assertSame(1, QuinzaineSummary::where('quinzaine_id', $this->qd->id)->count());
        $this->assertSame(0, TransportSnapshot::count());

        // Pointing works again, and closing again writes the snapshots back with the new day.
        $this->jour($this->ouvrier, $this->qi, '2026-10-22');
        $this->actingAs($this->manager)->post(route('settings.quinzaine.close', $this->qi))->assertSessionHasNoErrors();
        $this->assertSame(1, QuinzaineSummary::where('quinzaine_id', $this->qi->id)->count());
        $this->assertEqualsWithDelta(4 * 47, TransportSnapshot::sum('subtotal'), 0.01);
    }

    public function test_only_a_super_admin_of_that_farm_reopens(): void
    {
        $this->fermer();

        $this->actingAs($this->manager)->post(route('settings.quinzaine.reopen', $this->qi))->assertForbidden();
        $pointeur = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'acces_pointage' => 'saisie']);
        $this->actingAs($pointeur)->post(route('settings.quinzaine.reopen', $this->qi))->assertForbidden();
        $admin = User::factory()->create(['role' => 'super_admin', 'farm_id' => null, 'enterprise_id' => null]);
        $this->actingAs($admin)->withSession(['active_farm_id' => Farm::create(['name' => 'Autre'])->id])
            ->post(route('settings.quinzaine.reopen', $this->qi))->assertForbidden();

        $this->assertTrue($this->qi->fresh()->is_closed);
    }

    // --- Dates ---

    public function test_opening_dates_that_overlap_a_divisions_period_refuses_the_whole_request(): void
    {
        $seule = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'SEULE', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);

        $this->actingAs($this->manager)->post(route('settings.quinzaine'), ['enterprise_ids' => [$seule->id, $this->interim->id], 'label' => 'X', 'start_date' => '2026-10-20', 'end_date' => '2026-10-25'])
            ->assertSessionHasErrors('quinzaine');

        $this->assertSame(0, Quinzaine::where('enterprise_id', $seule->id)->count());
        $this->assertSame(1, Quinzaine::where('enterprise_id', $this->interim->id)->count());
    }

    public function test_a_period_is_31_days_at_most_and_the_same_dates_are_still_just_skipped(): void
    {
        $seule = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'SEULE', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $ouvrir = fn ($ids, $s, $e) => $this->actingAs($this->manager)->post(route('settings.quinzaine'), ['enterprise_ids' => $ids, 'label' => 'X', 'start_date' => $s, 'end_date' => $e]);

        $ouvrir([$seule->id], '2026-01-01', '2026-12-31')->assertSessionHasErrors('end_date');
        $ouvrir([$seule->id], '2026-01-01', '2026-02-01')->assertSessionHasErrors('end_date');
        $this->assertSame(0, Quinzaine::where('enterprise_id', $seule->id)->count());
        $ouvrir([$seule->id], '2026-01-01', '2026-01-31')->assertSessionHasNoErrors();

        $ouvrir([$seule->id, $this->interim->id], '2026-10-16', '2026-10-31')->assertSessionHas('success', 'Période ouverte pour SEULE. Déjà ouverte : INTERIM.');
    }

    public function test_moving_a_periods_dates_moves_every_division_together(): void
    {
        $this->actingAs($this->manager)->put(route('settings.quinzaine.update', $this->qd), ['label' => 'Nouveau', 'start_date' => '2026-10-17', 'end_date' => '2026-10-31'])
            ->assertSessionHas('success', 'Période modifiée pour INTERIM, DIRECT.');

        foreach ([$this->qi, $this->qd] as $q) {
            $this->assertSame('2026-10-17', $q->fresh()->start_date->toDateString());
            $this->assertSame('Nouveau', $q->fresh()->label);
        }
    }

    public function test_a_periods_dates_cant_move_past_a_pointed_day_or_a_closed_division_or_onto_another_period(): void
    {
        $edit = fn ($q, $s, $e, $as = null) => $this->actingAs($as ?? $this->manager)->put(route('settings.quinzaine.update', $q), ['label' => 'L', 'start_date' => $s, 'end_date' => $e]);

        // A day of the other division's line is in the way.
        $edit($this->qd, '2026-10-21', '2026-10-31')->assertSessionHasErrors('start_date');
        $this->assertSame('2026-10-16', $this->qd->fresh()->start_date->toDateString());

        // Onto the next period of one of the divisions.
        $this->periode($this->interim, '2026-11-01', '2026-11-15');
        $edit($this->qd, '2026-10-16', '2026-11-05')->assertSessionHasErrors('start_date');
        $this->assertSame('2026-10-31', $this->qi->fresh()->end_date->toDateString());

        // One division closed: the period's dates are settled, its label isn't.
        $this->qi->update(['is_closed' => true]);
        $edit($this->qd, '2026-10-16', '2026-10-30')->assertSessionHasErrors('start_date');
        $edit($this->qd, '2026-10-16', '2026-10-31')->assertSessionHas('success');
        $this->assertSame('L', $this->qd->fresh()->label);
    }

    public function test_a_division_locked_user_names_their_line_but_doesnt_move_the_period(): void
    {
        $pointeur = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'enterprise_id' => $this->direct->id, 'acces_pointage' => 'saisie']);

        $this->actingAs($pointeur)->put(route('settings.quinzaine.update', $this->qd), ['label' => 'L', 'start_date' => '2026-10-17', 'end_date' => '2026-10-31'])
            ->assertSessionHasErrors('start_date');
        $this->assertSame('2026-10-16', $this->qd->fresh()->start_date->toDateString());

        $this->actingAs($pointeur)->put(route('settings.quinzaine.update', $this->qd), ['label' => 'Le mien', 'start_date' => '2026-10-16', 'end_date' => '2026-10-31'])
            ->assertSessionHas('success');
        $this->assertSame('Le mien', $this->qd->fresh()->label);
        $this->assertNotSame('Le mien', $this->qi->fresh()->label);
    }

    public function test_a_fixed_salary_follows_the_dates_it_is_no_pointed_day(): void
    {
        $this->worker('004', $this->direct, null, ['salaire_mensuel' => 3500]);
        $this->actingAs($this->manager)->post(route('settings.quinzaine'), ['enterprise_ids' => [$this->direct->id], 'label' => 'N', 'start_date' => '2026-11-16', 'end_date' => '2026-11-30']);
        $q = Quinzaine::where('enterprise_id', $this->direct->id)->whereDate('start_date', '2026-11-16')->sole();
        $salaire = fn ($q) => PointageRecord::where('quinzaine_id', $q->id)->where('nature', PointageRecord::NATURE_SALAIRE);
        $edit = fn ($s, $e) => $this->actingAs($this->manager)->put(route('settings.quinzaine.update', $q), ['label' => 'N', 'start_date' => $s, 'end_date' => $e]);

        // The end moves: the salary stays dated the last day.
        $edit('2026-11-16', '2026-11-29')->assertSessionHasNoErrors();
        $this->assertSame('2026-11-29', $salaire($q)->sole()->date->toDateString());

        // No longer a second quinzaine: it's not paid here; a second quinzaine again: it is.
        $edit('2026-11-10', '2026-11-29')->assertSessionHasNoErrors();
        $this->assertSame(0, $salaire($q)->count());
        $edit('2026-11-17', '2026-11-29')->assertSessionHasNoErrors();
        $this->assertSame(1, $salaire($q)->count());
    }

    // --- The page ---

    public function test_the_page_gets_the_division_filter_and_the_date_it_compares_periods_with(): void
    {
        $this->actingAs($this->manager)->get(route('pointage.quinzaines', ['enterprise_id' => $this->direct->id]))->assertInertia(fn (Assert $page) => $page
            ->where('divisionFiltree', $this->direct->id)
            ->where('aujourdhui', '2026-11-02'));
    }
}
