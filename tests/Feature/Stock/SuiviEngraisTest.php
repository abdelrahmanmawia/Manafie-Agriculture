<?php

namespace Tests\Feature\Stock;

use App\Exports\SuiviEngraisExport;
use App\Models\Bloc;
use App\Models\Farm;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\StockReportPeriod;
use App\Services\SuiviEngraisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/** Suivi des engrais: the fertilizer sorties per day and bloc, doses and cost per hectare. */
class SuiviEngraisTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $magasinier;

    private Product $npk;

    private Product $uree;

    private Product $outil;

    private Bloc $b1;

    private Bloc $b2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-27');

        $this->farm = Farm::create(['name' => 'Test Farm']);
        $this->magasinier = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id]);
        $engrais = ProductCategory::create(['farm_id' => $this->farm->id, 'name' => 'Engrais', 'is_engrais' => true]);
        $outils = ProductCategory::create(['farm_id' => $this->farm->id, 'name' => 'Outils']);
        $product = fn ($name, $category) => Product::create(['farm_id' => $this->farm->id, 'category_id' => $category->id, 'name' => $name, 'unit_type' => 'kg', 'min_stock_level' => 0, 'unit_cost' => 0, 'is_active' => true]);
        $this->npk = $product('NPK 15-15-15', $engrais);
        $this->uree = $product('UREE 46%', $engrais);
        $this->outil = $product('Sécateur', $outils);
        $this->b1 = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1', 'area_ha' => 10]);
        $this->b2 = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B2', 'area_ha' => 20]);

        foreach ([$this->npk, $this->uree, $this->outil] as $p) {
            $this->actingAs($this->magasinier)->post(route('stock.movements.in'), [
                'product_id' => $p->id, 'quantity' => 1000, 'unit_cost' => 2, 'date' => '2026-09-01',
            ])->assertSessionHasNoErrors();
        }
        $this->sortie($this->npk, 10, $this->b1, '2026-09-20');
        $this->sortie($this->npk, 100, $this->b1, '2026-10-03');
        $this->sortie($this->npk, 50, $this->b2, '2026-10-03');
        $this->sortie($this->uree, 40, $this->b2, '2026-10-03');
        $this->sortie($this->npk, 30, $this->b1, '2026-10-10');
        $this->sortie($this->outil, 1, $this->b1, '2026-10-03'); // not an engrais
        $this->sortie($this->npk, 5, $this->b1, '2026-10-12', 'loss'); // lost, not applied
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sortie(Product $product, float $quantity, Bloc $bloc, string $date, string $type = 'consumption'): void
    {
        $this->actingAs($this->magasinier)->post(route('stock.manual-entries.store'), [
            'product_id' => $product->id, 'entry_type' => $type, 'quantity' => $quantity, 'date' => $date, 'bloc_id' => $bloc->id,
        ])->assertSessionHasNoErrors();
    }

    public function test_the_month_lists_each_day_and_engrais_per_bloc_with_doses_and_costs(): void
    {
        $this->actingAs($this->magasinier)->get(route('stock.reports.suivi-engrais', ['periode' => 'mois', 'mois' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Reports/SuiviEngrais')
                ->where('suivi.blocs.0.name', 'B1')
                ->where('suivi.blocs.1.name', 'B2')
                ->count('suivi.lignes', 3)
                ->where('suivi.lignes.0.date', '2026-10-03')
                ->where('suivi.lignes.0.engrais', 'NPK 15-15-15')
                ->where('suivi.lignes.0.quantites', [100, 50])
                ->where('suivi.lignes.1.engrais', 'UREE 46%')
                ->where('suivi.lignes.1.quantites', [0, 40])
                ->where('suivi.lignes.2.date', '2026-10-10')
                ->where('suivi.lignes.2.total', 30)
                ->count('suivi.engrais', 2)
                ->where('suivi.engrais.0.quantites', [130, 50])
                ->where('suivi.engrais.0.doses', [13, 2.5])     // 130 kg / 10 ha, 50 kg / 20 ha
                ->where('suivi.engrais.0.dose_totale', 6)      // 180 kg / 30 ha
                ->where('suivi.couts', [260, 180])            // at 2 DH/kg
                ->where('suivi.couts_ha', [26, 9])
                ->where('suivi.cout_total', 440)
                ->where('suivi.jours', 2)
                ->count('suivi.par_mois', 0)
                ->where('period.label', 'Octobre 2026'));
    }

    public function test_a_longer_period_adds_month_by_month(): void
    {
        $suivi = SuiviEngraisService::build($this->farm->id, StockReportPeriod::fromInput(['periode' => 'tout']));

        $this->assertCount(4, $suivi['lignes']);
        $this->assertSame([['2026-09', 'NPK 15-15-15', [10.0, 0.0]], ['2026-10', 'NPK 15-15-15', [130.0, 50.0]], ['2026-10', 'UREE 46%', [0.0, 40.0]]],
            array_map(fn ($l) => [$l['mois'], $l['engrais'], $l['quantites']], $suivi['par_mois']));
    }

    public function test_the_category_mark_decides_what_is_an_engrais(): void
    {
        $outils = ProductCategory::where('name', 'Outils')->sole();
        $this->actingAs($this->magasinier)->put(route('stock.categories.update', $outils), ['is_engrais' => true])->assertSessionHasNoErrors();

        $suivi = SuiviEngraisService::build($this->farm->id, StockReportPeriod::fromInput(['periode' => 'mois', 'mois' => '2026-10']));
        $this->assertContains('Sécateur', array_column($suivi['engrais'], 'engrais'));

        // Fuel and engrais are exclusive.
        $this->actingAs($this->magasinier)->put(route('stock.categories.update', $outils), ['is_carburant' => true])->assertSessionHasNoErrors();
        $this->assertFalse($outils->fresh()->is_engrais);
    }

    public function test_the_excel_export_computes_the_recap_and_doses(): void
    {
        $this->actingAs($this->magasinier)->get(route('stock.reports.suivi-engrais.export', ['periode' => 'mois', 'mois' => '2026-10']))
            ->assertOk()
            ->assertDownload('Suivi_Engrais_Octobre_2026.xlsx');

        $period = StockReportPeriod::fromInput(['periode' => 'mois', 'mois' => '2026-10']);
        $path = tempnam(sys_get_temp_dir(), 'engrais').'.xlsx';
        file_put_contents($path, Excel::raw(new SuiviEngraisExport(SuiviEngraisService::build($this->farm->id, $period), $period->label, 'Test Farm'), ExcelType::XLSX));
        $ws = IOFactory::load($path)->getActiveSheet();

        // Journal: B1 = D, B2 = E, TOTAL = F, COÛT = G; the surfaces on row 5.
        $this->assertSame('SURFACE (ha)', $ws->getCell('B5')->getValue());
        $this->assertEquals(150, $ws->getCell('F6')->getCalculatedValue());

        $recap = $this->rowAfter($ws, 'RÉCAPITULATIF PAR ENGRAIS', 'NPK 15-15-15');
        $this->assertEquals([130, 50, 180, 360], [$ws->getCell("D{$recap}")->getCalculatedValue(), $ws->getCell("E{$recap}")->getCalculatedValue(), $ws->getCell("F{$recap}")->getCalculatedValue(), $ws->getCell("G{$recap}")->getCalculatedValue()]);

        $dose = $this->rowAfter($ws, 'DOSE PAR HECTARE', 'NPK 15-15-15');
        $this->assertEquals([13, 2.5, 6, 12], [$ws->getCell("D{$dose}")->getCalculatedValue(), $ws->getCell("E{$dose}")->getCalculatedValue(), $ws->getCell("F{$dose}")->getCalculatedValue(), $ws->getCell("G{$dose}")->getCalculatedValue()]);

        $cout = $this->rowAfter($ws, 'COÛT PAR BLOC', 'COÛT / ha (DH)');
        $this->assertEquals([26, 9], [$ws->getCell("D{$cout}")->getCalculatedValue(), $ws->getCell("E{$cout}")->getCalculatedValue()]);
    }

    /** The row, below the section titled $section, whose column B shows $label. */
    private function rowAfter(Worksheet $ws, string $section, string $label): int
    {
        $inSection = false;
        for ($r = 1; $r <= $ws->getHighestRow(); $r++) {
            if (str_starts_with((string) $ws->getCell("A{$r}")->getValue(), $section)) {
                $inSection = true;
            } elseif ($inSection && $ws->getCell("B{$r}")->getCalculatedValue() === $label) {
                return $r;
            }
        }
        $this->fail("No {$label} under {$section}");
    }
}
