<?php

namespace Tests\Feature;

use App\Exports\DivisionsPointageExport;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\Quinzaine;
use App\Models\TransportCompany;
use App\Models\TransportVehicle;
use App\Models\TransportVehicleAbsence;
use App\Models\User;
use App\Services\TransportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/** The TRANSPORT sheet of the global export: riders per vehicle and day, its cost, as the app computes it. */
class TransportSheetExportTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $divA;

    private Enterprise $divB;

    private Quinzaine $qa;

    private User $pointeur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $mk = fn ($name) => Enterprise::create(['farm_id' => $this->farm->id, 'name' => $name, 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $this->divA = $mk('A');
        $this->divB = $mk('B');
        $this->qa = Quinzaine::create(['enterprise_id' => $this->divA->id, 'label' => 'Q', 'start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'is_closed' => false]);
        // B has the period too, with nothing pointed in it: the export must not choke on it.
        Quinzaine::create(['enterprise_id' => $this->divB->id, 'label' => 'Q', 'start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'is_closed' => false]);
        $this->pointeur = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id]);
    }

    private function sheet(): Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'transport').'.xlsx';
        file_put_contents($path, Excel::raw(new DivisionsPointageExport($this->qa->load('enterprise.farm')), ExcelType::XLSX));

        return IOFactory::load($path)->getSheetByName('TRANSPORT');
    }

    public function test_riders_per_day_absences_and_cost_match_the_app(): void
    {
        $company = TransportCompany::create(['farm_id' => $this->farm->id, 'name' => 'hafilaty', 'is_active' => true]);
        $bus = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $company->id, 'code' => 'TRANS 7 - ZINEB', 'driver_name' => 'YAHYA', 'price_per_person' => 47, 'is_active' => true]);
        $van = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $company->id, 'code' => 'TRANS NEZHA', 'fixed_net_per_day' => 300, 'is_active' => true]);
        $zineb = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'T7 ZINEB', 'transport_vehicle_id' => $bus->id, 'is_active' => true]);
        $nezha = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'NEZHA', 'transport_vehicle_id' => $van->id, 'is_active' => true]);
        $worker = fn ($name, $equipe) => Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => $this->divA->id, 'matricule' => $name, 'full_name' => $name, 'base_rate' => 97.44, 'is_active' => true, 'equipe_id' => $equipe?->id]);
        $z1 = $worker('Z1', $zineb);
        $z2 = $worker('Z2', $zineb);
        $n1 = $worker('N1', $nezha);
        $alone = $worker('SEUL', null);
        foreach ([[$z1, '16'], [$z2, '16'], [$z1, '17'], [$z1, '18'], [$n1, '16'], [$n1, '17'], [$alone, '16']] as [$w, $d]) {
            $this->actingAs($this->pointeur)->post('/pointage/cell', ['employee_id' => $w->id, 'quinzaine_id' => $this->qa->id, 'date' => "2026-10-{$d}", 'present' => true])->assertSessionHasNoErrors();
        }
        // The bus didn't come on the 18th: its rider is paid, the bus costs nothing.
        TransportVehicleAbsence::create(['transport_vehicle_id' => $bus->id, 'date' => '2026-10-18']);

        $ws = $this->sheet();
        // A N°, B TRANSPORT, C CHAUFFEUR, D ÉQUIPE(S), E.. the 16 days (16 → 31), then JOURS, PERS × J, PRIX, NET / J, TOTAL.
        $this->assertSame('2e Quinzaine octobre 2026', $ws->getCell('C2')->getValue());
        $this->assertSame(['N°', 'TRANSPORT', 'CHAUFFEUR', 'ÉQUIPE(S)', '16'], array_map(fn ($c) => (string) $ws->getCell("{$c}5")->getValue(), ['A', 'B', 'C', 'D', 'E']));
        $row = fn (string $code) => collect(range(6, 12))->first(fn ($r) => $ws->getCell("B{$r}")->getValue() === $code);
        $b = $row('TRANS 7 - ZINEB');
        $v = $row('TRANS NEZHA');
        $val = fn ($cell) => $ws->getCell($cell)->getCalculatedValue();

        $this->assertSame(['YAHYA', 'T7 ZINEB'], [$val("C{$b}"), $val("D{$b}")]);
        $this->assertSame([2, 1, 'ABS'], [$val("E{$b}"), $val("F{$b}"), $val("G{$b}")]);
        // JOURS 2 (the absent day isn't one), PERS × J 3, at 47 → 141; the van: 2 days at a flat 300.
        $this->assertEquals([2, 3, 141], [$val("U{$b}"), $val("V{$b}"), $val("Y{$b}")]);
        $this->assertEquals([2, 2, 600], [$val("U{$v}"), $val("V{$v}"), $val("Y{$v}")]);

        // SANS TRANSPORT counts the worker on no vehicle; TOTAL PAR JOUR is everyone present.
        $sans = $row('SANS TRANSPORT');
        $this->assertEquals(1, $val("E{$sans}"));
        $this->assertEquals(4, $val('E'.($sans + 1)));
        // The period's cost, also in LA SOMME, equals the app's.
        $appTotal = array_sum(array_column(app(TransportService::class)->previewForDateRange($this->qa->start_date, $this->qa->end_date, $this->farm->id), 'subtotal'));
        $this->assertEquals(741, $appTotal);
        $this->assertEquals($appTotal, $val('Y'.($sans + 1)));
        $this->assertEquals($appTotal, $val('C3'));
    }

    public function test_a_farm_without_transport_still_gets_its_sheet(): void
    {
        $ws = $this->sheet();

        $this->assertSame('SANS TRANSPORT', $ws->getCell('B6')->getValue());
        $this->assertEquals(0, $ws->getCell('C3')->getCalculatedValue());
    }
}
