<?php

namespace Tests\Feature;

use App\Exports\DivisionsPointageExport;
use App\Exports\PointageExport;
use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * The division fiches are live Excel workbooks laid out like the farm's own: their formulas,
 * evaluated, must give what the system pays and invoices.
 */
class PointageExportTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $manager;

    private Operation $dm;

    private Operation $irr;

    private Bloc $b1;

    private Bloc $b2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->dm = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Desherbage manuel', 'abbreviation' => 'D.M']);
        $this->irr = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Irrigation', 'abbreviation' => 'Irr']);
        $this->b1 = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        $this->b2 = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B2']);
    }

    private function division(array $attributes = []): Enterprise
    {
        return Enterprise::create($attributes + [
            'farm_id' => $this->farm->id, 'name' => 'PERSEALAND', 'contract_type' => 'avec_contrat',
            'default_brut_rate' => 97.44, 'invoiced_to_client' => false,
        ]);
    }

    private function quinzaine(Enterprise $division, string $start = '2026-10-01', string $end = '2026-10-15'): Quinzaine
    {
        return Quinzaine::create(['enterprise_id' => $division->id, 'label' => 'Q', 'start_date' => $start, 'end_date' => $end, 'is_closed' => false]);
    }

    private function worker(Enterprise $division, string $matricule, float $complement = 0): Employee
    {
        return Employee::create([
            'farm_id' => $this->farm->id, 'enterprise_id' => $division->id, 'matricule' => $matricule,
            'full_name' => "NOM{$matricule} PRENOM", 'last_name' => "NOM{$matricule}", 'first_name' => 'PRENOM',
            'cin' => "CIN{$matricule}", 'rib' => '225330061806507661010229', 'complement' => $complement,
            'base_rate' => 97.44, 'is_active' => true,
        ]);
    }

    /** Enters a day through the Grid, as a user would. */
    private function work(Employee $employee, Quinzaine $quinzaine, string $date, array $overrides = []): void
    {
        $this->actingAs($this->manager)->post('/pointage/cell', $overrides + [
            'employee_id' => $employee->id, 'quinzaine_id' => $quinzaine->id,
            'operation_id' => $this->dm->id, 'bloc_id' => $this->b1->id, 'date' => $date,
        ])->assertSessionHasNoErrors();
    }

    private function sheet(Quinzaine $quinzaine): Worksheet
    {
        $q = $quinzaine->fresh('enterprise');

        return $this->load(new PointageExport($q->enterprise, $q->start_date, $q->end_date, $q))->getActiveSheet();
    }

    private function load(object $export): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'fiche').'.xlsx';
        file_put_contents($path, Excel::raw($export, ExcelType::XLSX));

        return IOFactory::load($path);
    }

    /** Header label → column letter, for the fiche's header row. */
    private function columns(Worksheet $ws, int $headerRow): array
    {
        $columns = [];
        foreach ($ws->getRowIterator($headerRow, $headerRow)->current()->getCellIterator() as $cell) {
            if ($cell->getValue() !== null) {
                $columns[trim((string) $cell->getValue())] = $cell->getColumn();
            }
        }

        return $columns;
    }

    private function value(Worksheet $ws, string $cell): float
    {
        return (float) $ws->getCell($cell)->getCalculatedValue();
    }

    /** The footer value next to a label, wherever the fiche put it. */
    private function footer(Worksheet $ws, string $label): float
    {
        foreach ($ws->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() === $label) {
                    $col = $cell->getColumn();
                    do {
                        $col++;
                        $value = $ws->getCell($col.$cell->getRow())->getValue();
                    } while ($value === null);

                    return $this->value($ws, $col.$cell->getRow());
                }
            }
        }
        $this->fail("No footer line {$label}");
    }

    public function test_the_fiche_follows_the_division(): void
    {
        $this->assertSame(PointageExport::FICHE_FACTURE, PointageExport::ficheFor($this->division(['invoiced_to_client' => true, 'name' => 'A'])));
        $this->assertSame(PointageExport::FICHE_DECLAREE, PointageExport::ficheFor($this->division(['name' => 'B'])));
        $this->assertSame(PointageExport::FICHE_NON_DECLAREE, PointageExport::ficheFor($this->division(['name' => 'C', 'mo_declaree' => false])));
    }

    public function test_declared_fiche_lays_out_codes_and_its_totals_equal_what_is_paid(): void
    {
        $division = $this->division();
        $q = $this->quinzaine($division);
        $a = $this->worker($division, '001', 18.13);
        $b = $this->worker($division, '002');
        $this->work($a, $q, '2026-10-01');
        $this->work($a, $q, '2026-10-02', ['hours' => 2]);
        $this->work($a, $q, '2026-10-03', ['bloc_id' => $this->b2->id, 'operation_id' => $this->irr->id]);
        $this->work($b, $q, '2026-10-01', ['is_jf' => true]);

        $ws = $this->sheet($q);
        $col = $this->columns($ws, 5);

        $this->assertSame('PERSEALAND', $ws->getCell('A1')->getValue());
        $this->assertSame('1re Quinzaine octobre 2026', $ws->getCell('D2')->getValue());
        $this->assertSame(['N°', 'NOM', 'PRENOM', 'CIN', 'RIB'], array_slice(array_keys($col), 0, 5));
        $this->assertSame('001', $ws->getCell('A6')->getValue());
        $this->assertSame('225330061806507661010229', $ws->getCell($col['RIB'].'6')->getValue());
        $this->assertSame('B1|D.M', $ws->getCell('F6')->getValue());
        $this->assertSame('B2|Irr', $ws->getCell('H6')->getValue());
        $this->assertEquals(109.0, $ws->getCell($col['SAL  NET / J'].'6')->getValue());
        $this->assertEqualsWithDelta(18.13, $this->value($ws, $col['COMP'].'6'), 0.001);
        $this->assertEquals(3, $this->value($ws, $col['TOTAL'].'6'));

        $paid = fn (Employee $e) => (float) PointageRecord::where('employee_id', $e->id)->sum('net');
        $this->assertEqualsWithDelta($paid($a), $this->value($ws, $col['TOTAL NET'].'6'), 0.05);
        $this->assertEqualsWithDelta($paid($b), $this->value($ws, $col['TOTAL NET'].'7'), 0.05);
        $this->assertEqualsWithDelta($paid($a) + $paid($b), $this->value($ws, 'B3'), 0.05);
        // The bloc tables add up to the same thing.
        $this->assertEqualsWithDelta(0, $this->footer($ws, 'ECART'), 0.05);
    }

    public function test_invoiced_fiche_bills_like_payroll_for_both_invoicing_formulas(): void
    {
        $division = $this->division(['name' => 'P-AGRI INTERIM', 'invoiced_to_client' => true]);
        $payroll = app(PayrollService::class);

        foreach ([['2026-10-01', '2026-10-15'], ['2026-09-16', '2026-09-30']] as $i => [$start, $end]) {
            $q = $this->quinzaine($division, $start, $end);
            $worker = $this->worker($division, "00{$i}", 19.13);
            $this->work($worker, $q, $start, ['hours' => 3]);
            $this->work($worker, $q, date('Y-m-d', strtotime($start.' +1 day')), ['is_jf' => true]);
            $this->work($worker, $q, date('Y-m-d', strtotime($start.' +2 day')));

            $ws = $this->sheet($q);
            $col = $this->columns($ws, 4);
            $expected = $payroll->invoicingForRecords('avec_contrat', PointageRecord::where('quinzaine_id', $q->id)->get(), 19.13, true, $q->start_date);

            $this->assertEqualsWithDelta($expected['net_factur_j'], $this->value($ws, $col['NET FACTUR J'].'5'), 0.01, $start);
            $this->assertEqualsWithDelta($expected['total_ttc'], $this->value($ws, $col['TOTAL TTC'].'5'), 0.05, $start);
            $this->assertEqualsWithDelta($expected['total_ttc'], $this->value($ws, 'C3'), 0.05, $start);
        }
    }

    public function test_non_declared_fiche_has_no_rib_and_adds_the_4_percent_margin(): void
    {
        $division = $this->division(['name' => 'PERSEALAND NON DECLARE', 'mo_declaree' => false]);
        $q = $this->quinzaine($division);
        $worker = $this->worker($division, '001', 18.13);
        $this->work($worker, $q, '2026-10-01');
        $this->work($worker, $q, '2026-10-02', ['hours' => 4]);

        $ws = $this->sheet($q);
        $col = $this->columns($ws, 5);

        $this->assertArrayNotHasKey('RIB', $col);
        $this->assertSame('B1|D.M', $ws->getCell('E6')->getValue());
        $paid = (float) PointageRecord::sum('net');
        $this->assertEqualsWithDelta($paid, $this->value($ws, $col['TOTALNET'].'6'), 0.05);
        $this->assertEqualsWithDelta($this->footer($ws, 'MO NON DECLARE') * 1.04, $this->footer($ws, 'TOTAL  AVEC MARGE DE 4%'), 0.01);
        $this->assertEqualsWithDelta($this->footer($ws, 'TOTAL  AVEC MARGE DE 4%'), $this->value($ws, 'C3'), 0.01);
    }

    public function test_a_net_per_day_that_changed_during_the_period_still_totals_what_was_paid(): void
    {
        $division = $this->division();
        $q = $this->quinzaine($division);
        $worker = $this->worker($division, '001');
        $this->work($worker, $q, '2026-10-01');
        $this->work($worker, $q, '2026-10-02');
        PointageRecord::whereDate('date', '2026-10-01')->update(['net' => 100]);

        $ws = $this->sheet($q);
        $col = $this->columns($ws, 5);

        $this->assertEqualsWithDelta((float) PointageRecord::sum('net'), $this->value($ws, $col['TOTAL NET'].'6'), 0.01);
    }

    public function test_all_divisions_export_has_one_fiche_per_division(): void
    {
        $persealand = $this->division();
        $this->division(['name' => 'P-AGRI INTERIM', 'invoiced_to_client' => true]);
        $q = $this->quinzaine($persealand);
        $this->work($this->worker($persealand, '001'), $q, '2026-10-01');

        $book = $this->load(new DivisionsPointageExport($q->fresh('enterprise')));

        $this->assertSame(['PERSEALAND', 'P-AGRI INTERIM'], array_slice($book->getSheetNames(), 0, 2));
        $this->assertSame('B1|D.M', $book->getSheet(0)->getCell('F6')->getValue());
        $this->assertSame('Aucun pointage pour cette période', $book->getSheet(1)->getCell('B5')->getValue());
    }

    public function test_the_export_routes_download_a_workbook(): void
    {
        $division = $this->division();
        $q = $this->quinzaine($division);
        $this->work($this->worker($division, '001'), $q, '2026-10-01');

        $this->actingAs($this->manager)->get(route('pointage.export', $q->id))->assertOk()->assertDownload();
        $this->actingAs($this->manager)->get(route('pointage.exportAllDivisions', $q->id))->assertOk()->assertDownload();
    }
}
