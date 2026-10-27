<?php

namespace Tests\Feature;

use App\Exports\PointageExport;
use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\TransportCompany;
use App\Models\TransportVehicle;
use App\Models\User;
use App\Services\CoutFermeService;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * What a period costs the farm: the interim invoice (TTC) for an invoiced division, the net + 4%
 * for a non-declared one, the net for a declared direct one — each its fiche's LA SOMME — plus
 * the period's transport.
 */
class CoutFermeTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $pointeur;

    private Operation $dm;

    private Bloc $b1;

    /** @var array<string, Quinzaine> */
    private array $q = [];

    /** @var array<string, Employee> */
    private array $w = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->pointeur = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id]);
        $this->dm = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Desherbage manuel', 'abbreviation' => 'D.M']);
        $this->b1 = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        $company = TransportCompany::create(['farm_id' => $this->farm->id, 'name' => 'hafilaty', 'is_active' => true]);
        $bus = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $company->id, 'code' => 'TRANS 2', 'price_per_person' => 47, 'is_active' => true]);
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'T2', 'transport_vehicle_id' => $bus->id, 'is_active' => true]);

        $divisions = [
            'interim' => ['name' => 'PRESTATAIRE MO DECLARE', 'invoiced_to_client' => true],
            'declare' => ['name' => 'PERSEALAND', 'invoiced_to_client' => false],
            'non_declare' => ['name' => 'PERSEALAND NON DECLARE', 'invoiced_to_client' => false, 'mo_declaree' => false],
        ];
        foreach ($divisions as $key => $attributes) {
            $division = Enterprise::create($attributes + ['farm_id' => $this->farm->id, 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
            $this->q[$key] = Quinzaine::create(['enterprise_id' => $division->id, 'label' => 'Q', 'start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'is_closed' => false]);
            $this->w[$key] = Employee::create([
                'farm_id' => $this->farm->id, 'enterprise_id' => $division->id, 'matricule' => $key, 'full_name' => strtoupper($key),
                'base_rate' => 97.44, 'complement' => 9.13, 'is_active' => true, 'equipe_id' => $equipe->id,
            ]);
        }
        // Two days each (one with 2 H.S.), on the bus at 47 DH a person.
        foreach ($this->w as $key => $worker) {
            foreach (['2026-10-16' => 0, '2026-10-17' => 2] as $date => $hs) {
                $this->actingAs($this->pointeur)->post('/pointage/cell', [
                    'employee_id' => $worker->id, 'quinzaine_id' => $this->q[$key]->id, 'date' => $date,
                    'operation_id' => $this->dm->id, 'bloc_id' => $this->b1->id, 'hours' => $hs,
                ])->assertSessionHasNoErrors();
            }
        }
    }

    private function laSomme(Quinzaine $quinzaine): float
    {
        $q = $quinzaine->fresh('enterprise');
        $path = tempnam(sys_get_temp_dir(), 'cout').'.xlsx';
        file_put_contents($path, Excel::raw(new PointageExport($q->enterprise, $q->start_date, $q->end_date, $q), ExcelType::XLSX));
        $ws = IOFactory::load($path)->getActiveSheet();

        return (float) $ws->getCell(PointageExport::ficheFor($q->enterprise) === PointageExport::FICHE_DECLAREE ? 'B3' : 'C3')->getCalculatedValue();
    }

    public function test_each_division_costs_its_fiches_la_somme(): void
    {
        $couts = app(CoutFermeService::class)->parQuinzaine(Quinzaine::with('enterprise')->get());
        $net = fn ($key) => (float) PointageRecord::where('quinzaine_id', $this->q[$key]->id)->sum('net');

        $interim = $couts[$this->q['interim']->id];
        $ttc = app(PayrollService::class)->invoicingForRecords('avec_contrat', PointageRecord::where('quinzaine_id', $this->q['interim']->id)->get(), 9.13, true, $this->q['interim']->start_date)['total_ttc'];
        $this->assertSame(PointageExport::FICHE_FACTURE, $interim['fiche']);
        $this->assertEqualsWithDelta($ttc, $interim['ttc'], 0.01);
        $this->assertEqualsWithDelta($ttc, $interim['cout'], 0.01);
        $this->assertGreaterThan($interim['net'], $interim['cout']); // the invoice is more than the workers get

        $declare = $couts[$this->q['declare']->id];
        $this->assertNull($declare['ttc']);
        $this->assertEqualsWithDelta($net('declare'), $declare['cout'], 0.01);

        $nonDeclare = $couts[$this->q['non_declare']->id];
        $this->assertEqualsWithDelta($net('non_declare') * 0.04, $nonDeclare['marge'], 0.01);
        $this->assertEqualsWithDelta($net('non_declare') * 1.04, $nonDeclare['cout'], 0.01);

        // Each is what its fiche says at the top.
        foreach (['interim', 'declare', 'non_declare'] as $key) {
            $this->assertEqualsWithDelta($this->laSomme($this->q[$key]), $couts[$this->q[$key]->id]['cout'], 0.05, $key);
        }
    }

    public function test_the_quinzaines_page_and_the_accueil_show_the_period_cost_with_transport(): void
    {
        $couts = app(CoutFermeService::class)->parQuinzaine(Quinzaine::with('enterprise')->get());
        $main = array_sum(array_column($couts, 'cout'));
        $transport = 3 * 2 * 47; // three riders, two days, 47 DH a person

        $this->actingAs($this->pointeur)->get(route('pointage.quinzaines'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('quinzaines.0.stats.cout', fn ($v) => $v > 0)
                ->where('coutsPeriodes.2026-10-16_2026-10-31.transport', fn ($v) => abs($v - $transport) < 0.01)
                ->where('coutsPeriodes.2026-10-16_2026-10-31.ttc', fn ($v) => abs($v - $couts[$this->q['interim']->id]['ttc']) < 0.01)
                ->where('coutsPeriodes.2026-10-16_2026-10-31.total', fn ($v) => abs($v - ($main + $transport)) < 0.05));

        $this->actingAs($this->pointeur)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pointageDuJour.cout_periode.total', fn ($v) => abs($v - ($main + $transport)) < 0.05)
                ->where('pointageDuJour.cout_periode.transport', fn ($v) => abs($v - $transport) < 0.01));
    }

    public function test_a_closed_period_keeps_its_frozen_transport(): void
    {
        foreach ($this->q as $q) {
            $q->update(['is_closed' => true]);
        }
        app(\App\Services\TransportService::class)->generateSnapshot($this->q['interim']);
        // The price changes afterwards: the closed period still costs what was frozen.
        TransportVehicle::query()->update(['price_per_person' => 100]);

        $periode = app(CoutFermeService::class)->parPeriode(Quinzaine::with('enterprise')->get(), $this->farm->id);
        $this->assertEqualsWithDelta(3 * 2 * 47, $periode['2026-10-16_2026-10-31']['transport'], 0.01);
    }
}
