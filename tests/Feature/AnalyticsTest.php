<?php

namespace Tests\Feature;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\Sector;
use App\Models\TransportCompany;
use App\Models\TransportVehicle;
use App\Models\User;
use App\Services\CoutFermeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The Analyses page's figures: each what its label says, on the same dates, or none rather than a wrong one. */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $manager;

    private Enterprise $interim;

    private Enterprise $direct;

    private Bloc $b1;

    private Bloc $b2;

    private Operation $op;

    private array $octobre;

    private array $novembre;

    private Employee $w1;

    private Employee $w2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00');

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->interim = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'Interim', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44, 'invoiced_to_client' => true]);
        $this->direct = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'Direct', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44, 'invoiced_to_client' => false]);
        $this->b1 = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1', 'area_ha' => 10]);
        $this->b2 = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B2', 'area_ha' => 10]);
        $this->op = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Irrigation', 'abbreviation' => 'Irr']);

        $periode = fn ($start, $end) => collect([$this->interim, $this->direct])->mapWithKeys(fn ($e) => [$e->id => Quinzaine::create(['enterprise_id' => $e->id, 'label' => $start, 'start_date' => $start, 'end_date' => $end, 'is_closed' => false])])->all();
        $this->octobre = $periode('2026-10-16', '2026-10-31');
        $this->novembre = $periode('2026-11-01', '2026-11-15');

        $company = TransportCompany::create(['farm_id' => $this->farm->id, 'name' => 'Co']);
        $bus = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $company->id, 'code' => 'BUS', 'price_per_person' => 50, 'is_active' => true]);
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'T', 'transport_vehicle_id' => $bus->id, 'is_active' => true]);
        $this->w1 = $this->worker('W1', $this->interim, $equipe->id);
        $this->w2 = $this->worker('W2', $this->direct);

        // B1 is listed first and costs the least; B2 costs the most.
        $this->jour($this->w1, $this->octobre[$this->interim->id], '2026-10-20', $this->b1, 100, $bus->id);
        $this->jour($this->w2, $this->octobre[$this->direct->id], '2026-10-20', $this->b2, 300);
        $this->jour($this->w2, $this->octobre[$this->direct->id], '2026-10-21', $this->b2, 300);
        // The running period has only just begun.
        $this->jour($this->w2, $this->novembre[$this->direct->id], '2026-11-01', $this->b2, 300);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function worker(string $name, Enterprise $division, ?int $equipeId = null): Employee
    {
        return Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => $division->id, 'matricule' => $name, 'full_name' => $name, 'base_rate' => 97.44, 'is_active' => true, 'equipe_id' => $equipeId]);
    }

    private function jour(Employee $e, Quinzaine $q, string $date, ?Bloc $bloc, float $net, ?int $vehicule = null, array $autres = []): PointageRecord
    {
        return PointageRecord::create($autres + ['employee_id' => $e->id, 'quinzaine_id' => $q->id, 'date' => $date, 'present' => true, 'operation_id' => $bloc ? $this->op->id : null, 'bloc_id' => $bloc?->id,
            'hours' => 0, 'rate' => 97.44, 'brut' => 97.44, 'net' => $net, 'transport_vehicle_id' => $vehicule]);
    }

    private function recolte(string $date, float $kg, float $ca, ?Bloc $bloc = null, ?Sector $sector = null): void
    {
        Harvest::create(['farm_id' => $this->farm->id, 'bloc_id' => ($bloc ?? $this->b1)->id, 'sector_id' => $sector?->id, 'date' => $date, 'variety' => 'Hass', 'quantity_kg' => $kg, 'total_revenue_dh' => $ca]);
    }

    private function props(array $query = [], ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->manager)->get(route('analytics.index', $query))->assertOk();

        return json_decode(json_encode($response->viewData('page')['props']), true);
    }

    private function cout(?string $key = null): array
    {
        $periodes = app(CoutFermeService::class)->parPeriode(Quinzaine::with('enterprise')->get(), $this->farm->id);

        return $key ? $periodes[$key] : $periodes;
    }

    public function test_the_top_bloc_is_the_costliest(): void
    {
        $p = $this->props();

        $this->assertSame(['B2', 'B1'], array_column($p['blocCosts'], 'name'));
    }

    public function test_the_page_shows_what_the_farm_really_pays_next_to_the_net(): void
    {
        $p = $this->props();
        $couts = $this->cout();

        $this->assertEqualsWithDelta(1000, $p['totalNet'], 0.01);
        $this->assertEqualsWithDelta(array_sum(array_column($couts, 'total')), $p['cout']['total'], 0.01);
        $this->assertEqualsWithDelta(array_sum(array_column($couts, 'ttc')), $p['cout']['ttc'], 0.01);
        $this->assertGreaterThan(0, $p['cout']['ttc']);
        $this->assertEqualsWithDelta(55, $p['cout']['transport'], 0.01); // one rider at 50, + 10%
        $this->assertGreaterThan($p['totalNet'], $p['cout']['total']);
    }

    public function test_a_division_alone_has_its_cost_without_the_farms_transport_and_a_bloc_has_no_cost(): void
    {
        $p = $this->props(['enterprise_id' => $this->direct->id]);
        $this->assertEqualsWithDelta(900, $p['cout']['total'], 0.01);
        $this->assertNull($p['cout']['transport']);

        $p = $this->props(['bloc_id' => $this->b2->id]);
        $this->assertNull($p['cout']);
        $this->assertTrue($p['coutBaseNet']);
        $this->assertEqualsWithDelta(900, $p['totalNet'], 0.01);
    }

    public function test_the_cost_per_ha_is_an_average_per_finished_quinzaine_not_a_running_total(): void
    {
        $p = $this->props();
        $key = '2026-10-16_2026-10-31';

        // October only: November is still running, and only just begun.
        $this->assertEqualsWithDelta($this->cout($key)['total'] / 20, $p['avgCostPerHa'], 0.01);

        // Adding a finished period averages, it doesn't add up.
        $septembre = Quinzaine::create(['enterprise_id' => $this->direct->id, 'label' => 'S', 'start_date' => '2026-09-16', 'end_date' => '2026-09-30', 'is_closed' => false]);
        $this->jour($this->w2, $septembre, '2026-09-20', $this->b2, 200);
        $p = $this->props();
        $this->assertEqualsWithDelta(($this->cout($key)['total'] / 20 + 200 / 20) / 2, $p['avgCostPerHa'], 0.01);

        // Only the running period: it's all there is to show.
        $p = $this->props(['quinzaine_id' => $this->novembre[$this->direct->id]->id]);
        $this->assertEqualsWithDelta(300 / 20, $p['avgCostPerHa'], 0.01);
    }

    public function test_the_trend_marks_the_running_period(): void
    {
        $p = $this->props();

        $this->assertSame([false, true], array_column($p['trend'], 'en_cours'));
        $this->assertEqualsWithDelta(700, $p['trend'][0]['total_net'], 0.01);
        $this->assertEqualsWithDelta(300, $p['trend'][1]['total_net'], 0.01);
    }

    public function test_the_net_per_worked_day_leaves_out_what_is_no_worked_day(): void
    {
        // 4 days for 1000 DH so far. An unworked J.F, a fixed salary and its 0 DH day change nothing.
        $this->jour($this->w1, $this->octobre[$this->interim->id], '2026-10-31', null, 90, null, ['present' => false, 'is_jf' => true]);
        $fixe = $this->worker('FIXE', $this->interim);
        $this->jour($fixe, $this->octobre[$this->interim->id], '2026-10-31', null, 3500, null, ['present' => false, 'nature' => PointageRecord::NATURE_SALAIRE]);
        $fixe->update(['salaire_mensuel' => 3500]);
        $this->jour($fixe, $this->octobre[$this->interim->id], '2026-10-20', $this->b1, 0);

        $p = $this->props();

        $this->assertEqualsWithDelta(1000 / 4, $p['netMoyenParJour'], 0.01);
        $this->assertSame(3, $p['employeeCount']);
    }

    public function test_the_charts_add_up_to_the_total_with_what_is_on_no_bloc(): void
    {
        $this->jour($this->w1, $this->octobre[$this->interim->id], '2026-10-31', null, 90, null, ['present' => false, 'is_jf' => true]);
        $this->jour($this->w1, $this->octobre[$this->interim->id], '2026-10-22', null, 100);
        $this->jour($this->w2, $this->octobre[$this->direct->id], '2026-10-31', null, 3500, null, ['present' => false, 'nature' => PointageRecord::NATURE_SALAIRE]);

        $p = $this->props();

        $this->assertEqualsWithDelta(90, $p['horsBlocs']['jf'], 0.01);
        $this->assertEqualsWithDelta(100, $p['horsBlocs']['a_preciser'], 0.01);
        $this->assertEqualsWithDelta(3500, $p['horsBlocs']['salaires'], 0.01);
        $this->assertEqualsWithDelta($p['totalNet'], array_sum(array_column($p['blocCosts'], 'total_net')) + array_sum($p['horsBlocs']), 0.01);
    }

    public function test_a_top_ten_says_how_much_other_operations_cost(): void
    {
        foreach (range(1, 11) as $i) {
            $op = Operation::create(['farm_id' => $this->farm->id, 'name' => "Op{$i}", 'abbreviation' => "O{$i}"]);
            $this->jour($this->worker("X{$i}", $this->direct), $this->octobre[$this->direct->id], '2026-10-25', $this->b1, 10, null, ['operation_id' => $op->id]);
        }

        $p = $this->props();

        $this->assertCount(10, $p['opCosts']);
        $this->assertEqualsWithDelta(20, $p['autresOperations'], 0.01); // 12 operations: 2 at 10 DH left out
    }

    public function test_an_interval_works_with_one_end_open(): void
    {
        $oct = $this->octobre[$this->interim->id]->id;
        $nov = $this->novembre[$this->interim->id]->id;

        $this->assertEqualsWithDelta(300, $this->props(['quinzaine_from' => $nov])['totalNet'], 0.01);
        $this->assertEqualsWithDelta(700, $this->props(['quinzaine_to' => $oct])['totalNet'], 0.01);
        $this->assertEqualsWithDelta(1000, $this->props(['quinzaine_from' => $oct])['totalNet'], 0.01);
        $this->assertEqualsWithDelta(1000, $this->props(['quinzaine_from' => $nov, 'quinzaine_to' => $oct])['totalNet'], 0.01);
    }

    public function test_without_a_harvest_there_is_no_margin_or_cost_per_kg(): void
    {
        $p = $this->props();

        foreach (['costPerKg', 'laborCostPercentage', 'margePerHa', 'revenuePerHa', 'yieldPerHa'] as $key) {
            $this->assertNull($p[$key], $key);
        }
    }

    public function test_cost_is_compared_with_the_harvest_of_the_same_dates(): void
    {
        $this->recolte('2026-10-20', 100, 5000);
        $this->recolte('2026-06-01', 900, 90000); // before any pointage in the app

        $p = $this->props(['quinzaine_id' => $this->octobre[$this->interim->id]->id]);
        $cout = $this->cout('2026-10-16_2026-10-31')['total'];
        $this->assertEqualsWithDelta(100, $p['totalHarvestKg'], 0.01);
        $this->assertEqualsWithDelta($cout / 100, $p['costPerKg'], 0.01);
        $this->assertEqualsWithDelta($cout / 5000 * 100, $p['laborCostPercentage'], 0.01);
        $this->assertEqualsWithDelta((5000 - $cout) / 20, $p['margePerHa'], 0.01);
        $this->assertEqualsWithDelta(5000 / 20, $p['revenuePerHa'], 0.01);
        $this->assertSame(1, $p['recoltesHorsPeriode']);

        // All periods: still only the dates pay is known for, and it says so.
        $p = $this->props();
        $this->assertEqualsWithDelta(100, $p['totalHarvestKg'], 0.01);
        $this->assertSame(1, $p['recoltesHorsPeriode']);
    }

    public function test_a_division_or_a_secteur_alone_has_no_figure_against_the_farms_harvest(): void
    {
        $sector = Sector::create(['bloc_id' => $this->b1->id, 'name' => 'S1', 'area_ha' => 4]);
        $this->recolte('2026-10-20', 100, 5000, $this->b1, $sector);

        $p = $this->props(['enterprise_id' => $this->direct->id]);
        $this->assertFalse($p['ratiosComparables']);
        foreach (['costPerKg', 'laborCostPercentage', 'margePerHa'] as $key) {
            $this->assertNull($p[$key], $key);
        }
        $this->assertEqualsWithDelta(100, $p['totalHarvestKg'], 0.01);

        // A secteur narrows the harvest, not the pay (a day has no secteur); its yield is per its own area.
        $p = $this->props(['bloc_id' => $this->b1->id, 'sector_id' => $sector->id]);
        $this->assertEqualsWithDelta(100, $p['totalNet'], 0.01);
        $this->assertEqualsWithDelta(100 / 4, $p['yieldPerHa'], 0.01);
        $this->assertNull($p['costPerKg']);

        // A bloc compares its own net: the farm's cost isn't known per bloc.
        $p = $this->props(['bloc_id' => $this->b1->id]);
        $this->assertEqualsWithDelta(100 / 100, $p['costPerKg'], 0.01);
        $this->assertEqualsWithDelta((5000 - 100) / 10, $p['margePerHa'], 0.01);
    }

    public function test_a_finished_period_compares_with_the_one_before(): void
    {
        $septembre = Quinzaine::create(['enterprise_id' => $this->direct->id, 'label' => 'S', 'start_date' => '2026-09-16', 'end_date' => '2026-09-30', 'is_closed' => false]);
        $this->jour($this->w2, $septembre, '2026-09-20', $this->b2, 200);
        $id = $this->octobre[$this->interim->id]->id;

        $p = $this->props(['quinzaine_from' => $id, 'quinzaine_to' => $id]);

        $this->assertFalse($p['periodeEnCours']);
        $this->assertEqualsWithDelta(200, $p['precedente']['net'], 0.01);
        $this->assertSame(1, $p['precedente']['employes']);
        $this->assertEqualsWithDelta(200, $p['precedente']['net_par_jour'], 0.01);
        $this->assertEqualsWithDelta(200, $p['precedente']['cout'], 0.01);
        $this->assertEqualsWithDelta(10, $p['precedente']['cost_per_ha'], 0.01);
        $this->assertStringStartsWith('2026-09-16', $p['precedente']['start_date']);

        // The same bloc and the same division on both sides of the comparison.
        $p = $this->props(['quinzaine_from' => $id, 'quinzaine_to' => $id, 'bloc_id' => $this->b1->id]);
        $this->assertEqualsWithDelta(0, $p['precedente']['net'], 0.01);
        $this->assertNull($p['precedente']['cout']);
    }

    public function test_only_one_finished_period_with_a_period_before_it_compares(): void
    {
        $oct = $this->octobre[$this->interim->id]->id;
        $nov = $this->novembre[$this->interim->id]->id;

        // All periods: no comparison.
        $this->assertNull($this->props()['precedente']);
        // The first period has nothing before it.
        $this->assertNull($this->props(['quinzaine_from' => $oct, 'quinzaine_to' => $oct])['precedente']);
        // A period still running is marked, not compared: its first days would read as a fall.
        $p = $this->props(['quinzaine_from' => $nov, 'quinzaine_to' => $nov]);
        $this->assertNull($p['precedente']);
        $this->assertTrue($p['periodeEnCours']);
    }

    public function test_the_export_has_the_same_figures_for_the_same_filters(): void
    {
        $this->jour($this->w1, $this->octobre[$this->interim->id], '2026-10-31', null, 90, null, ['present' => false, 'is_jf' => true]);
        $this->recolte('2026-10-20', 100, 5000);

        $response = $this->actingAs($this->manager)->get(route('analytics.export'))->assertOk()->assertDownload();
        $ws = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheetByName('ANALYSES');
        $lignes = [];
        foreach ($ws->getRowIterator() as $row) {
            $r = $row->getRowIndex();
            $lignes[trim((string) $ws->getCell('A'.$r)->getValue())] ??= $ws->getCell('B'.$r)->getOldCalculatedValue() ?? $ws->getCell('B'.$r)->getValue();
        }
        $p = $this->props();

        $this->assertEqualsWithDelta($p['cout']['total'], $lignes['Coût pour la ferme'], 0.01);
        $this->assertEqualsWithDelta($p['cout']['ttc'], $lignes['TTC intérim'], 0.01);
        $this->assertEqualsWithDelta($p['totalNet'], $lignes['Net versé aux ouvriers'], 0.01);
        $this->assertEquals($p['employeeCount'], $lignes['Ouvriers payés sur la période']);
        $this->assertEqualsWithDelta($p['avgCostPerHa'], $lignes['Coût moyen / ha par quinzaine'], 0.01);
        $this->assertEqualsWithDelta(90, $lignes['J.F non travaillés (sans opération)'], 0.01);
        // The operations and what's on none add up to the net.
        $this->assertEqualsWithDelta($p['totalNet'], $lignes['TOTAL'], 0.01);
        $this->assertEqualsWithDelta(100, $lignes['Total récolte (Kg)'], 0.01);
        $this->assertEqualsWithDelta($p['costPerKg'], $lignes["Coût main d'œuvre / Kg"], 0.01);
    }

    public function test_the_export_follows_the_filters_and_the_scope(): void
    {
        $fichier = fn ($query, $as = null) => \PhpOffice\PhpSpreadsheet\IOFactory::load(
            $this->actingAs($as ?? $this->manager)->get(route('analytics.export', $query))->assertOk()->baseResponse->getFile()->getPathname()
        )->getSheetByName('ANALYSES');
        $valeur = function ($ws, string $label) {
            foreach ($ws->getRowIterator() as $row) {
                if (trim((string) $ws->getCell('A'.$row->getRowIndex())->getValue()) === $label) {
                    return $ws->getCell('B'.$row->getRowIndex())->getValue();
                }
            }

            return null;
        };

        $this->assertEqualsWithDelta(900, $valeur($fichier(['bloc_id' => $this->b2->id]), 'Net versé aux ouvriers'), 0.01);
        $this->assertNull($valeur($fichier(['bloc_id' => $this->b2->id]), 'Coût pour la ferme'));

        // A division-locked user exports their division, whatever the link says; another farm's is refused.
        $locked = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'enterprise_id' => $this->direct->id]);
        $this->assertEqualsWithDelta(900, $valeur($fichier(['enterprise_id' => $this->interim->id], $locked), 'Net versé aux ouvriers'), 0.01);
        $autre = Farm::create(['name' => 'Autre']);
        $etranger = Enterprise::create(['farm_id' => $autre->id, 'name' => 'E', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $this->actingAs($this->manager)->get(route('analytics.export', ['enterprise_id' => $etranger->id]))->assertForbidden();
    }

    public function test_a_division_locked_user_gets_their_division_and_no_picker(): void
    {
        $locked = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'enterprise_id' => $this->direct->id]);

        $p = $this->props(['enterprise_id' => $this->interim->id], $locked);

        $this->assertSame([], $p['enterprises']);
        $this->assertSame($this->direct->id, $p['enterprise']['id']);
        $this->assertEqualsWithDelta(900, $p['totalNet'], 0.01);
    }
}
