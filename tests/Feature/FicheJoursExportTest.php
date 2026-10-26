<?php

namespace Tests\Feature;

use App\Exports\FicheJoursExport;
use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\Quinzaine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/** The fiche des jours: per transport, days / H.S / J.F by payment method, checked against the fiches. */
class FicheJoursExportTest extends TestCase
{
    use RefreshDatabase;

    private const RIB = '225330061806507661010229';

    private Farm $farm;

    private Enterprise $prestataire;

    private Enterprise $nonDeclaree;

    private User $manager;

    private Operation $op;

    private Bloc $bloc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Persealand']);
        $mk = fn ($name, $attributes = []) => Enterprise::create($attributes + [
            'farm_id' => $this->farm->id, 'name' => $name, 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44, 'invoiced_to_client' => false,
        ]);
        $this->prestataire = $mk('PRESTATAIRE', ['invoiced_to_client' => true]);
        $this->nonDeclaree = $mk('NON DECLARE', ['mo_declaree' => false]);
        foreach ([$this->prestataire, $this->nonDeclaree] as $division) {
            Quinzaine::create(['enterprise_id' => $division->id, 'label' => '1QZ', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'is_closed' => false]);
        }
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->op = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Irrigation', 'abbreviation' => 'Irr']);
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
    }

    private function worker(string $name, Enterprise $division, ?Equipe $equipe = null, string $rib = '', array $extra = []): Employee
    {
        return Employee::create($extra + [
            'farm_id' => $this->farm->id, 'enterprise_id' => $division->id, 'matricule' => $name, 'full_name' => $name,
            'last_name' => $name, 'first_name' => 'P', 'cin' => "CIN{$name}", 'rib' => $rib,
            'base_rate' => 97.44, 'is_active' => true, 'equipe_id' => $equipe?->id,
        ]);
    }

    private function cell(Employee $employee, string $date, array $data = []): void
    {
        $quinzaine = Quinzaine::where('enterprise_id', $employee->enterprise_id)->sole();
        $this->actingAs($this->manager)->post('/pointage/cell', $data + [
            'employee_id' => $employee->id, 'quinzaine_id' => $quinzaine->id, 'date' => $date,
            'operation_id' => $this->op->id, 'bloc_id' => $this->bloc->id,
        ])->assertSessionHasNoErrors();
    }

    private function book(?int $enterpriseId = null): Spreadsheet
    {
        $reference = Quinzaine::where('enterprise_id', $this->prestataire->id)->sole();
        $path = tempnam(sys_get_temp_dir(), 'jours').'.xlsx';
        file_put_contents($path, Excel::raw(new FicheJoursExport($reference->load('enterprise.farm'), $enterpriseId), ExcelType::XLSX));

        return IOFactory::load($path);
    }

    /** Row of the first cell in column A or B holding $text. */
    private function find(Worksheet $ws, string $text): int
    {
        for ($r = 1; $r < 100; $r++) {
            if ($ws->getCell("A{$r}")->getValue() === $text || $ws->getCell("B{$r}")->getValue() === $text) {
                return $r;
            }
        }
        $this->fail("No row {$text}");
    }

    public function test_each_transport_lists_days_hs_and_jf_by_payment_method_chef_first(): void
    {
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'T2 MAKKA']);
        $chef = $this->worker('ZCHEF', $this->prestataire, $equipe, self::RIB);
        $equipe->update(['chef_employee_id' => $chef->id]);
        $vir = $this->worker('AVIR', $this->prestataire, $equipe, self::RIB);
        $esp = $this->worker('BESP', $this->nonDeclaree, $equipe);
        foreach (['2026-10-01', '2026-10-02'] as $date) {
            $this->cell($chef, $date);
            $this->cell($vir, $date);
        }
        $this->cell($vir, '2026-10-03', ['hours' => 2]);
        $this->cell($esp, '2026-10-01', ['is_jf' => true]);

        $book = $this->book();
        $this->assertSame(['RECAP', 'T2 MAKKA'], $book->getSheetNames());
        $ws = $book->getSheetByName('T2 MAKKA');
        $this->assertSame('TRANSPORT : T2 MAKKA', $ws->getCell('A3')->getValue());
        $this->assertSame("Chef d'équipe : ZCHEF", $ws->getCell('A4')->getValue());
        $this->assertSame('PAIEMENT PAR VIREMENT', $ws->getCell('A6')->getValue());
        $this->assertSame(['N°', 'NOM', 'PRENOM', 'CIN', 'TOTAL JOURS', 'H.S', 'J.F', 'SIGNATURE'], $ws->rangeToArray('A7:H7')[0]);
        $this->assertSame('ZCHEF', $ws->getCell('B8')->getValue());
        $this->assertSame("CHEF D'ÉQUIPE", $ws->getCell('H8')->getValue());

        $avir = $this->find($ws, 'AVIR');
        $this->assertSame(3, $ws->getCell("E{$avir}")->getValue());
        $this->assertEquals(2, $ws->getCell("F{$avir}")->getValue());
        $besp = $this->find($ws, 'BESP');
        $this->assertGreaterThan($this->find($ws, 'PAIEMENT PAR ESPÈCES'), $besp);
        $this->assertSame(1, $ws->getCell("E{$besp}")->getValue());
        $this->assertSame(1, $ws->getCell("G{$besp}")->getValue());

        $total = $this->find($ws, 'TOTAL JOURS TRANSPORT T2 MAKKA');
        $this->assertEquals(6, $ws->getCell("E{$total}")->getCalculatedValue());
        $this->assertEquals(2, $ws->getCell("F{$total}")->getCalculatedValue());
        $this->assertEquals(1, $ws->getCell("G{$total}")->getCalculatedValue());
    }

    public function test_the_recap_adds_up_each_transport_and_matches_the_fiches(): void
    {
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'NEZHA']);
        $vir = $this->worker('AVIR', $this->prestataire, $equipe, self::RIB);
        $esp = $this->worker('BESP', $this->nonDeclaree);
        $this->cell($vir, '2026-10-01');
        $this->cell($vir, '2026-10-02');
        $this->cell($esp, '2026-10-03');
        // A fixed salary with no day pointed has nothing to show on a fiche of days.
        $this->worker('SALARIE', $this->nonDeclaree, null, '', ['salaire_mensuel' => 3500]);

        $recap = $this->book()->getSheetByName('RECAP');
        $this->assertSame(['TRANSPORT', 'OUVRIERS VIREMENT', 'JOURS VIREMENT', 'OUVRIERS ESPÈCES', 'JOURS ESPÈCES', 'TOTAL OUVRIERS', 'TOTAL JOURS', 'H.S', 'J.F'], $recap->rangeToArray('A5:I5')[0]);
        $this->assertSame([['NEZHA', 1, 2, 0, 0, 1, 2], ['Sans équipe', 0, 0, 1, 1, 1, 1]],
            array_map(fn ($row) => array_slice($row, 0, 7), $recap->rangeToArray('A6:I7', null, true, false)));
        $total = $this->find($recap, 'TOTAL GENERAL');
        $this->assertEquals(3, $recap->getCell("G{$total}")->getCalculatedValue());

        $this->assertEquals(2, $recap->getCell('B'.$this->find($recap, 'AGRI INTERIM (A.I)'))->getValue());
        $this->assertEquals(1, $recap->getCell('B'.$this->find($recap, 'NON DÉCLARÉ'))->getValue());
        $this->assertEquals(0, $recap->getCell('B'.$this->find($recap, 'ÉCART (fiche - fiches)'))->getCalculatedValue());
    }

    public function test_a_division_locked_user_only_gets_their_division_and_the_route_downloads(): void
    {
        $this->cell($this->worker('AVIR', $this->prestataire, null, self::RIB), '2026-10-01');
        $this->cell($this->worker('BESP', $this->nonDeclaree), '2026-10-02');

        $ws = $this->book($this->nonDeclaree->id)->getSheetByName('Sans équipe');
        $this->find($ws, 'BESP');
        $this->assertNotSame('AVIR', $ws->getCell('B8')->getValue());

        $q = Quinzaine::where('enterprise_id', $this->prestataire->id)->sole();
        $this->actingAs($this->manager)->get(route('pointage.ficheJours', $q->id))->assertOk()->assertDownload('Fiche_Jours_1QZ.xlsx');
    }
}
