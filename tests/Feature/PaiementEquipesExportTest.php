<?php

namespace Tests\Feature;

use App\Exports\PaiementEquipesExport;
use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/** The payment-per-équipe workbook pays exactly what the records pay, grouped by chef d'équipe. */
class PaiementEquipesExportTest extends TestCase
{
    use RefreshDatabase;

    private const RIB = '225330061806507661010229';

    private Farm $farm;

    private Enterprise $declaree;

    private Enterprise $prestataire;

    private Enterprise $nonDeclaree;

    private User $manager;

    private Operation $op;

    private Bloc $bloc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $mk = fn ($name, $attributes = []) => Enterprise::create($attributes + [
            'farm_id' => $this->farm->id, 'name' => $name, 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44, 'invoiced_to_client' => false,
        ]);
        $this->declaree = $mk('PERSEALAND');
        $this->prestataire = $mk('PRESTATAIRE', ['invoiced_to_client' => true]);
        $this->nonDeclaree = $mk('NON DECLARE', ['mo_declaree' => false]);
        foreach ([$this->declaree, $this->prestataire, $this->nonDeclaree] as $division) {
            Quinzaine::create(['enterprise_id' => $division->id, 'label' => 'Q', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'is_closed' => false]);
        }
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->op = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Irrigation', 'abbreviation' => 'Irr']);
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
    }

    private function worker(string $name, Enterprise $division, ?Equipe $equipe = null, string $rib = '', float $complement = 0): Employee
    {
        return Employee::create([
            'farm_id' => $this->farm->id, 'enterprise_id' => $division->id, 'matricule' => $name, 'full_name' => $name,
            'last_name' => $name, 'first_name' => 'P', 'cin' => "CIN{$name}", 'rib' => $rib, 'complement' => $complement,
            'base_rate' => 97.44, 'is_active' => true, 'equipe_id' => $equipe?->id,
        ]);
    }

    private function cell(Employee $employee, string $date, array $data = [])
    {
        $quinzaine = Quinzaine::where('enterprise_id', $employee->enterprise_id)->sole();

        return $this->actingAs($this->manager)->post('/pointage/cell', $data + [
            'employee_id' => $employee->id, 'quinzaine_id' => $quinzaine->id, 'date' => $date,
            'operation_id' => $this->op->id, 'bloc_id' => $this->bloc->id,
        ])->assertSessionHasNoErrors();
    }

    private function book(?int $enterpriseId = null): Spreadsheet
    {
        $reference = Quinzaine::where('enterprise_id', $this->declaree->id)->sole();
        $path = tempnam(sys_get_temp_dir(), 'paie').'.xlsx';
        file_put_contents($path, Excel::raw(new PaiementEquipesExport($reference->load('enterprise'), $enterpriseId), ExcelType::XLSX));

        return IOFactory::load($path);
    }

    /** Header label → column letter on an équipe sheet (row 4). */
    private function columns(Worksheet $ws): array
    {
        $columns = [];
        foreach ($ws->getRowIterator(4, 4)->current()->getCellIterator() as $cell) {
            if ($cell->getValue() !== null) {
                $columns[str_replace("\n", ' ', (string) $cell->getValue())] = $cell->getColumn();
            }
        }

        return $columns;
    }

    private function row(Worksheet $ws, string $nom): int
    {
        for ($r = 5; $r < 100; $r++) {
            if ($ws->getCell("B{$r}")->getValue() === $nom) {
                return $r;
            }
        }
        $this->fail("No row {$nom}");
    }

    private function value(Worksheet $ws, string $cell)
    {
        return $ws->getCell($cell)->getCalculatedValue();
    }

    public function test_each_equipe_gets_a_sheet_across_divisions_chef_first(): void
    {
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'E1']);
        $chef = $this->worker('ZCHEF', $this->prestataire, $equipe, self::RIB, 19.13);
        $equipe->update(['chef_employee_id' => $chef->id]);
        $member = $this->worker('AMEMBER', $this->declaree, $equipe);
        $alone = $this->worker('ALONE', $this->nonDeclaree);
        $this->cell($chef, '2026-10-01');
        $this->cell($member, '2026-10-01');
        $this->cell($alone, '2026-10-02');

        $book = $this->book();

        $this->assertSame(['RÉSUMÉ', 'E1', 'Sans équipe'], $book->getSheetNames());
        $ws = $book->getSheetByName('E1');
        $this->assertSame('ZCHEF', $ws->getCell('B5')->getValue());
        $this->assertSame('AMEMBER', $ws->getCell('B6')->getValue());
        $this->assertSame('PRESTATAIRE', $ws->getCell('E5')->getValue());
        $this->assertStringContainsString("Chef d'équipe : ZCHEF", $ws->getCell('A2')->getValue());
    }

    public function test_net_a_payer_is_what_the_records_pay_including_hs_and_holidays(): void
    {
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'E1']);
        $worker = $this->worker('W', $this->declaree, $equipe, self::RIB, 9.13);
        $this->cell($worker, '2026-10-01');
        $this->cell($worker, '2026-10-02', ['hours' => 3]);
        $this->cell($worker, '2026-10-03', ['is_jf' => true]);
        $this->cell($worker, '2026-10-04', ['operation_id' => null, 'bloc_id' => null, 'present' => true]);
        $this->cell($worker, '2026-10-05', ['operation_id' => null, 'bloc_id' => null, 'is_jf' => true]); // unworked holiday

        $ws = $this->book()->getSheetByName('E1');
        $col = $this->columns($ws);
        $r = $this->row($ws, 'W');

        $this->assertEquals(4, $this->value($ws, $col['TOTAL JOURS'].$r));
        $this->assertEquals(100.0, $ws->getCell($col['SAL. NET / J'].$r)->getValue());
        $this->assertEqualsWithDelta((float) PointageRecord::sum('net'), $this->value($ws, $col['NET A PAYER'].$r), 0.01);
        $this->assertStringContainsString('1 j. opération à préciser', $ws->getCell($col['OBSERVATION'].$r)->getValue());
    }

    public function test_a_fixed_monthly_salary_has_its_own_column_not_primes(): void
    {
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'E1']);
        $pointeur = $this->worker('POINTEUR', $this->nonDeclaree, $equipe);
        $pointeur->update(['salaire_mensuel' => 3500]);
        $this->cell($pointeur, '2026-10-01', ['hours' => 2]);
        $quinzaine = Quinzaine::where('enterprise_id', $this->nonDeclaree->id)->sole();
        PointageRecord::create(['employee_id' => $pointeur->id, 'quinzaine_id' => $quinzaine->id, 'date' => '2026-10-15', 'nature' => PointageRecord::NATURE_SALAIRE,
            'present' => false, 'hours' => 0, 'is_jf' => false, 'rate' => 3500, 'brut' => 0, 'net' => 3500]);

        $ws = $this->book()->getSheetByName('E1');
        $col = $this->columns($ws);
        $r = $this->row($ws, 'POINTEUR');

        $this->assertEquals(1, $this->value($ws, $col['TOTAL JOURS'].$r));
        $this->assertEquals(3500, $ws->getCell($col['SALAIRE FIXE'].$r)->getValue());
        $this->assertNull($ws->getCell($col['PRIMES (H.S / J.F)'].$r)->getValue());
        $this->assertEqualsWithDelta(3500, $this->value($ws, $col['NET A PAYER'].$r), 0.01);
    }

    public function test_payment_method_and_coverage(): void
    {
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'E1']);
        $cases = [
            // nom => [division, rib, PAIEMENT, AMO / CNSS]
            'DECL_RIB' => [$this->declaree, self::RIB, 'VIREMENT', 'CNSS'],
            'DECL_CASH' => [$this->declaree, '', 'ESPÈCES', 'AMO'],
            'DECL_BAD' => [$this->declaree, '12345', 'RIB À VÉRIFIER', 'CNSS'],
            'PREST_CASH' => [$this->prestataire, '', 'ESPÈCES', 'CNSS'],
            'ND_RIB' => [$this->nonDeclaree, self::RIB, 'VIREMENT', 'AMO'],
        ];
        foreach ($cases as $nom => [$division, $rib]) {
            $this->cell($this->worker($nom, $division, $equipe, $rib), '2026-10-01');
        }

        $ws = $this->book()->getSheetByName('E1');
        $col = $this->columns($ws);
        foreach ($cases as $nom => [, , $paiement, $couverture]) {
            $r = $this->row($ws, $nom);
            $this->assertSame($paiement, $this->value($ws, $col['PAIEMENT'].$r), $nom);
            $this->assertSame($couverture, $ws->getCell($col['AMO / CNSS'].$r)->getValue(), $nom);
        }
    }

    public function test_resume_totals_by_payment_method_and_headcount(): void
    {
        $e1 = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'E1']);
        $e2 = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'E2']);
        $this->cell($this->worker('A', $this->declaree, $e1, self::RIB), '2026-10-01');
        $this->cell($this->worker('B', $this->declaree, $e1), '2026-10-01');
        $this->cell($this->worker('C', $this->declaree, $e2), '2026-10-02');

        $ws = $this->book()->getSheetByName('RÉSUMÉ');
        $net = fn (string $nom) => (float) PointageRecord::whereHas('employee', fn ($q) => $q->where('full_name', $nom))->sum('net');

        $this->assertSame('E1', $ws->getCell('A5')->getValue());
        $this->assertEquals(2, $this->value($ws, 'B5'));
        $this->assertEqualsWithDelta($net('A') + $net('B'), $this->value($ws, 'D5'), 0.01);
        $this->assertEqualsWithDelta($net('A'), $this->value($ws, 'E5'), 0.01);
        $this->assertEqualsWithDelta($net('B'), $this->value($ws, 'F5'), 0.01);
        $this->assertSame('TOTAL GÉNÉRAL', $ws->getCell('A7')->getValue());
        $this->assertEqualsWithDelta((float) PointageRecord::sum('net'), $this->value($ws, 'D7'), 0.01);
        // Headcount (title row 10, header row 11): E1 had 2 present on the 1st, E2 one on the 2nd.
        $this->assertSame('E1', $ws->getCell('A12')->getValue());
        $this->assertEquals(2, $this->value($ws, 'B12'));
        $this->assertEquals(1, $this->value($ws, 'C13'));
    }

    public function test_a_division_locked_user_only_gets_their_division(): void
    {
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'E1']);
        $this->cell($this->worker('MINE', $this->declaree, $equipe), '2026-10-01');
        $this->cell($this->worker('OTHER', $this->prestataire, $equipe), '2026-10-01');

        $ws = $this->book($this->declaree->id)->getSheetByName('E1');

        $this->assertSame('MINE', $ws->getCell('B5')->getValue());
        $this->assertNotSame('OTHER', $ws->getCell('B6')->getValue());
    }

    public function test_the_route_downloads_a_workbook(): void
    {
        $this->cell($this->worker('A', $this->declaree), '2026-10-01');
        $q = Quinzaine::where('enterprise_id', $this->declaree->id)->sole();

        $this->actingAs($this->manager)->get(route('pointage.paiementEquipes', $q->id))->assertOk()->assertDownload();
    }
}
