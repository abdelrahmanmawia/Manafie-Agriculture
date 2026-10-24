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
use App\Services\FicheImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * A fiche written by PointageExport reads back into the same pay, and the team workbook gives the
 * équipes and the presence of a period without fiches yet.
 */
class FicheImportTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $division;

    private Operation $irr;

    private Bloc $b1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->division = Enterprise::create([
            'farm_id' => $this->farm->id, 'name' => 'PERSEALAND', 'contract_type' => 'avec_contrat',
            'default_brut_rate' => 97.44, 'invoiced_to_client' => false,
        ]);
        $this->irr = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Irrigation', 'abbreviation' => 'Irr']);
        $this->b1 = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
    }

    private function worker(string $nom, string $cin, float $complement): Employee
    {
        return Employee::create([
            'farm_id' => $this->farm->id, 'enterprise_id' => $this->division->id, 'matricule' => $cin, 'full_name' => "{$nom} P",
            'last_name' => $nom, 'first_name' => 'P', 'cin' => $cin, 'complement' => $complement, 'base_rate' => 97.44, 'is_active' => true,
        ]);
    }

    /** A real fiche for 1–15 October, written by PointageExport from records then removed. */
    private function fiche(): array
    {
        $q = Quinzaine::create(['enterprise_id' => $this->division->id, 'label' => 'Q', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'is_closed' => false]);
        $manager = \App\Models\User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $chef = $this->worker('CHEF', 'C1', 19.13);
        $ouvrier = $this->worker('OUVRIER', 'O1', 9.13);
        foreach ([[$chef, '2026-10-01', 2], [$chef, '2026-10-02', 0], [$ouvrier, '2026-10-01', 0]] as [$e, $date, $hours]) {
            $this->actingAs($manager)->post('/pointage/cell', [
                'employee_id' => $e->id, 'quinzaine_id' => $q->id, 'date' => $date,
                'operation_id' => $this->irr->id, 'bloc_id' => $this->b1->id, 'hours' => $hours,
            ])->assertSessionHasNoErrors();
        }
        $net = (float) PointageRecord::sum('net');
        $path = tempnam(sys_get_temp_dir(), 'fiche').'.xlsx';
        file_put_contents($path, Excel::raw(new PointageExport($this->division, $q->start_date, $q->end_date, $q->fresh()), ExcelType::XLSX));

        return [$path, $net];
    }

    /** A team workbook: one équipe sheet, its chef in A2 and a 1 per day present. */
    private function teams(array $rows, int $firstDay): string
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('T1 CHEF');
        $ws->setCellValue('A1', 'FICHE DE POINTAGE');
        $ws->setCellValue('A2', "Chef d'équipe : CHEF P     |     quinzaine");
        foreach (['N°', 'NOM', 'PRENOM', 'CIN'] as $i => $label) {
            $ws->setCellValue([$i + 1, 4], $label);
        }
        foreach (range(0, 3) as $d) {
            $ws->setCellValue([5 + $d, 4], (string) ($firstDay + $d));
        }
        foreach ($rows as $i => [$nom, $cin, $days]) {
            $ws->fromArray([$i + 1, $nom, 'P', $cin], null, 'A'.(5 + $i));
            foreach ($days as $d) {
                $ws->setCellValue([5 + $d, 5 + $i], 1);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'teams').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    public function test_a_fiche_round_trips_and_the_team_workbook_gives_equipes_and_presence(): void
    {
        $company = TransportCompany::create(['farm_id' => $this->farm->id, 'name' => 'Co']);
        $bus = TransportVehicle::create(['farm_id' => $this->farm->id, 'transport_company_id' => $company->id, 'code' => 'TRANS 1 - CHEF']);
        [$fiche, $net] = $this->fiche();
        $teams = $this->teams([['CHEF', 'C1', []], ['OUVRIER', 'O1', []]], 1);
        $presence = $this->teams([['CHEF', 'C1', [0, 1]], ['NOUVEAU', 'N9', [2]]], 16);

        $report = app(FicheImportService::class)->run($this->farm->id, [
            ['label' => '1ERE Qz octobre 2026', 'start' => '2026-10-01', 'end' => '2026-10-15', 'fiches' => [[$fiche, $this->division->id]], 'presence' => null],
            ['label' => '2EME Qz octobre 2026', 'start' => '2026-10-16', 'end' => '2026-10-31', 'fiches' => [], 'presence' => $presence],
        ], $teams, []);

        // The old period is gone, two new ones exist.
        $this->assertSame(['1ERE Qz octobre 2026', '2EME Qz octobre 2026'], Quinzaine::orderBy('start_date')->pluck('label')->all());
        $first = Quinzaine::where('label', '1ERE Qz octobre 2026')->sole();
        $this->assertSame(3, PointageRecord::where('quinzaine_id', $first->id)->count());
        $this->assertEqualsWithDelta($net, (float) PointageRecord::where('quinzaine_id', $first->id)->sum('net'), 0.05);
        $this->assertEqualsWithDelta($report['files'][0]['net_file'], $report['files'][0]['net_imported'], 0.05);

        // The équipe, its chef and its vehicle; worked days ride it.
        $equipe = Equipe::sole();
        $this->assertSame('T1 CHEF', $equipe->name);
        $this->assertSame('CHEF P', $equipe->chef->full_name);
        $this->assertSame($bus->id, $equipe->transport_vehicle_id);
        $this->assertSame(3, PointageRecord::where('transport_vehicle_id', $bus->id)->where('quinzaine_id', $first->id)->count());

        // The presence-only period: present, operation à préciser; a new worker is created.
        $second = Quinzaine::where('label', '2EME Qz octobre 2026')->sole();
        $this->assertSame(3, PointageRecord::where('quinzaine_id', $second->id)->where('present', true)->whereNull('operation_id')->count());
        // The new worker joins the sheet's équipe before their day is written, so it rides its vehicle.
        $nouveau = Employee::where('cin', 'N9')->sole();
        $this->assertSame($equipe->id, $nouveau->equipe_id);
        $this->assertSame(3, PointageRecord::where('quinzaine_id', $second->id)->where('transport_vehicle_id', $bus->id)->count());
    }

    public function test_a_stock_entry_linked_to_a_pointage_record_stops_the_import(): void
    {
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('manual_stock_entries');
        \Illuminate\Support\Facades\DB::table('manual_stock_entries')->insert(array_intersect_key([
            'farm_id' => $this->farm->id, 'pointage_record_id' => 999, 'product_id' => 1, 'quantity' => 1, 'entry_type' => 'consumption',
            'date' => '2026-10-01', 'entry_date' => '2026-10-01', 'created_by' => 1, 'entered_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], array_flip($columns)));
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        $this->expectExceptionMessage('Des sorties de stock sont liées à des pointages');

        app(FicheImportService::class)->run($this->farm->id, [], $this->teams([], 1), []);
    }
}
