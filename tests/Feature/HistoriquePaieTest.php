<?php

namespace Tests\Feature;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\User;
use App\Services\PayrollService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The Historique de Paie table, its Excel export, the payslip and the état global. */
class HistoriquePaieTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $manager;

    private Enterprise $declaree;

    private Enterprise $tache;

    private Quinzaine $octobre;

    private Quinzaine $octobreTache;

    private Quinzaine $novembre;

    private Employee $w1;

    private Employee $w2;

    private Operation $op;

    private Bloc $bloc;

    private ?object $capture = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00');

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $mk = fn ($name, $extra = []) => Enterprise::create($extra + ['farm_id' => $this->farm->id, 'name' => $name, 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44, 'invoiced_to_client' => false]);
        $this->declaree = $mk('Declaree');
        $this->tache = $mk('Tache', ['mo_declaree' => false]);
        $q = fn ($e, $s, $f) => Quinzaine::create(['enterprise_id' => $e->id, 'label' => 'Q/'.$s, 'start_date' => $s, 'end_date' => $f, 'is_closed' => false]);
        $this->octobre = $q($this->declaree, '2026-10-16', '2026-10-31');
        $this->octobreTache = $q($this->tache, '2026-10-16', '2026-10-31');
        $this->novembre = $q($this->declaree, '2026-11-01', '2026-11-15');
        $this->op = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Irrigation', 'abbreviation' => 'Irr']);
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
        $this->w1 = $this->worker('AAA', $this->declaree);
        $this->w2 = $this->worker('BBB', $this->tache);
        $this->jour($this->w1, $this->octobre, '2026-10-20');
        $this->jour($this->w1, $this->novembre, '2026-11-01');
        $this->jour($this->w2, $this->octobreTache, '2026-10-20');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function worker(string $name, Enterprise $e, float $comp = 9.13, array $extra = []): Employee
    {
        return Employee::create($extra + ['farm_id' => $this->farm->id, 'enterprise_id' => $e->id, 'matricule' => $name, 'full_name' => $name, 'cin' => "C{$name}", 'base_rate' => 97.44, 'complement' => $comp, 'is_active' => true]);
    }

    private function jour(Employee $e, Quinzaine $q, string $date, array $extra = []): PointageRecord
    {
        return PointageRecord::create($extra + ['employee_id' => $e->id, 'quinzaine_id' => $q->id, 'date' => $date, 'present' => true, 'operation_id' => $this->op->id, 'bloc_id' => $this->bloc->id,
            'hours' => 0, 'rate' => 97.44, 'brut' => 97.44, 'net' => 100]);
    }

    private function props(array $query = []): array
    {
        return json_decode(json_encode($this->actingAs($this->manager)->get(route('payroll.history', $query))->assertOk()->viewData('page')['props']), true);
    }

    public function test_each_amount_leads_to_its_payslip_and_each_period_says_where_it_stands(): void
    {
        $p = $this->props();

        $this->assertSame(['en_cours', 'ouverte'], array_column($p['periods'], 'statut'));
        $lignes = collect($p['history'][$this->w1->id])->keyBy('period_key');
        $this->assertSame([$this->octobre->id], $lignes['2026-10-16_2026-10-31']['quinzaines']);
        $this->assertSame([$this->novembre->id], $lignes['2026-11-01_2026-11-15']['quinzaines']);

        $this->octobre->update(['is_closed' => true]);
        app(PayrollService::class)->generateSnapshot($this->octobre);
        $this->octobreTache->update(['is_closed' => true]);
        app(PayrollService::class)->generateSnapshot($this->octobreTache);
        $p = $this->props();
        $this->assertSame('cloturee', $p['periods'][1]['statut']);
        $lignes = collect($p['history'][$this->w1->id])->keyBy('period_key');
        $this->assertSame([$this->octobre->id], $lignes['2026-10-16_2026-10-31']['quinzaines']);
        $this->assertEqualsWithDelta(100, $lignes['2026-10-16_2026-10-31']['total_net'], 0.01);
    }

    public function test_a_worker_paid_in_two_divisions_in_a_period_has_both_payslips(): void
    {
        $this->jour($this->w1, $this->octobreTache, '2026-10-21');

        $ligne = collect($this->props()['history'][$this->w1->id])->firstWhere('period_key', '2026-10-16_2026-10-31');

        $this->assertEqualsCanonicalizing([$this->octobre->id, $this->octobreTache->id], $ligne['quinzaines']);
        $this->assertEqualsWithDelta(200, $ligne['total_net'], 0.01);
    }

    public function test_a_payslip_of_each_period_downloads_for_a_worker_paid_in_it(): void
    {
        $this->actingAs($this->manager)->get(route('payroll.payslip', [$this->w1->id, $this->octobre->id]))->assertOk();
        $this->actingAs($this->manager)->get(route('payroll.payslip', [$this->w2->id, $this->octobreTache->id]))->assertOk();
    }

    public function test_a_non_declared_payslip_has_no_cotisation_lines_and_says_a_la_tache(): void
    {
        $captured = $this->capturePdf();
        $this->actingAs($this->manager)->get(route('payroll.payslip', [$this->w2->id, $this->octobreTache->id]));
        $html = view('exports.payslip', $captured->data)->render();

        $this->assertFalse($captured->data['declare']);
        $this->assertStringNotContainsString('CNSS', $html);
        $this->assertStringNotContainsString('AMO', $html);
        $this->assertStringContainsString('À la tâche', $html);

        $captured = $this->capturePdf();
        $this->actingAs($this->manager)->get(route('payroll.payslip', [$this->w1->id, $this->octobre->id]));
        $this->assertStringContainsString('CNSS', view('exports.payslip', $captured->data)->render());
    }

    public function test_a_payslip_keeps_an_unworked_holiday_apart_from_the_days_present(): void
    {
        $this->jour($this->w1, $this->octobre, '2026-10-31', ['present' => false, 'is_jf' => true, 'operation_id' => null, 'bloc_id' => null, 'net' => 100]);

        $captured = $this->capturePdf();
        $this->actingAs($this->manager)->get(route('payroll.payslip', [$this->w1->id, $this->octobre->id]));

        $this->assertSame(1, $captured->data['payslip']['days']);
        $this->assertSame(1, $captured->data['payslip']['jf_chomes']);
        $this->assertStringContainsString('Jour férié payé (non travaillé)', view('exports.payslip', $captured->data)->render());
    }

    public function test_a_days_net_stored_to_the_centime_is_no_complement_on_the_payslip(): void
    {
        $this->w1->update(['complement' => 0]);
        PointageRecord::where('quinzaine_id', $this->octobre->id)->get()->each(fn ($r) => $r->delete());
        foreach (['2026-10-20', '2026-10-21', '2026-10-22'] as $date) {
            $this->jour($this->w1, $this->octobre, $date, ['net' => 90.87]);
        }

        $captured = $this->capturePdf();
        $this->actingAs($this->manager)->get(route('payroll.payslip', [$this->w1->id, $this->octobre->id]));
        $html = view('exports.payslip', $captured->data)->render();

        $this->assertStringContainsString('Arrondi des centimes', $html);
        $this->assertStringNotContainsString('Complément', $html);
    }

    public function test_a_payslip_doesnt_move_when_the_complement_changes(): void
    {
        $avant = $this->payslip();
        $this->w1->update(['complement' => 50]);
        $apres = $this->payslip();

        $this->assertEqualsWithDelta($avant['total_gains'], $apres['total_gains'], 0.001);
        $this->assertEqualsWithDelta(100, $apres['total_net'], 0.001);
        $this->assertEqualsWithDelta($apres['total_net'], $apres['total_gains'] - $apres['total_retenues'], 0.001);
    }

    private function payslip(): array
    {
        $captured = $this->capturePdf();
        $this->actingAs($this->manager)->get(route('payroll.payslip', [$this->w1->id, $this->octobre->id]));

        return $captured->data['payslip'];
    }

    public function test_the_etat_global_lists_numbers_and_counts_only_who_has_records(): void
    {
        // In the division, inactive, nothing in this period: no line, not counted.
        $this->worker('ZZZ', $this->declaree, 0, ['is_active' => false]);
        $this->worker('AA0', $this->declaree);
        // A day still "à préciser" is a day present; the salary row isn't one.
        $this->jour($this->w1, $this->octobre, '2026-10-21', ['operation_id' => null, 'bloc_id' => null]);

        $captured = $this->capturePdf();
        $this->actingAs($this->manager)->get(route('payroll.general-payslip', $this->octobre->id));
        $html = view('exports.general_payslip', $captured->data)->render();

        $this->assertCount(1, $captured->data['employees']);
        preg_match_all('#<tr>\s*<td class="text-center">(\d+)</td>#', $html, $numeros);
        $this->assertSame(['1'], $numeros[1]);
        $this->assertMatchesRegularExpression('#Nombre de Salari[^<]*</td>\s*<td[^>]*>1<#', $html);
        $this->assertMatchesRegularExpression('#<td class="text-center font-bold">2</td>#', $html);
        $this->assertStringContainsString('200,00 DH', $html);
    }

    public function test_the_etat_global_file_name_is_clean_whatever_the_label(): void
    {
        $this->actingAs($this->manager)->get(route('payroll.general-payslip', $this->octobre->id))
            ->assertOk()->assertDownload('Etat_Global_Q_2026-10-16.pdf');
    }

    public function test_the_export_follows_the_filters_on_the_page(): void
    {
        $ws = fn (array $q = [], $as = null) => \PhpOffice\PhpSpreadsheet\IOFactory::load(
            $this->actingAs($as ?? $this->manager)->get(route('payroll.history.export', $q))->assertOk()->assertDownload()->baseResponse->getFile()->getPathname()
        )->getSheetByName('HISTORIQUE');
        $ligne = function ($sheet, string $nom) {
            foreach ($sheet->getRowIterator(5) as $row) {
                $r = $row->getRowIndex();
                if ($sheet->getCell('B'.$r)->getValue() === $nom) {
                    return $r;
                }
            }

            return null;
        };
        $this->worker('SANS', $this->declaree);

        // Newest period first; one line per worker with an amount; the cumul and totals add up.
        $s = $ws();
        $this->assertStringContainsString('1QZ Novembre 2026', $s->getCell('E4')->getValue());
        $this->assertStringContainsString('en cours', $s->getCell('E4')->getValue());
        $this->assertStringContainsString('non clôturée', $s->getCell('F4')->getValue());
        $this->assertEquals(100, $s->getCell('E'.$ligne($s, 'AAA'))->getValue());
        $this->assertEquals(200, $s->getCell('G'.$ligne($s, 'AAA'))->getOldCalculatedValue());
        $this->assertNull($ligne($s, 'SANS'));
        $total = $ligne($s, 'BBB') + 1;
        $this->assertSame('TOTAL', $s->getCell('A'.$total)->getValue());
        $this->assertEquals(300, $s->getCell('G'.$total)->getOldCalculatedValue());

        // Everyone, one period, a search, a division.
        $this->assertNotNull($ligne($ws(['avec_montant' => '0']), 'SANS'));
        $s = $ws(['periode' => '2026-10-16_2026-10-31']);
        $this->assertStringContainsString('2QZ Octobre 2026', $s->getCell('E4')->getValue());
        $this->assertEquals(100, $s->getCell('F'.$ligne($s, 'AAA'))->getOldCalculatedValue());
        $this->assertNull($ligne($ws(['q' => 'bbb']), 'AAA'));
        $this->assertNull($ligne($ws(['enterprise_id' => $this->tache->id]), 'AAA'));

        // A division-locked user exports their own; another farm's division is refused.
        $locked = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'enterprise_id' => $this->tache->id]);
        $this->assertNull($ligne($ws(['enterprise_id' => $this->declaree->id], $locked), 'AAA'));
        $autre = Enterprise::create(['farm_id' => Farm::create(['name' => 'Autre'])->id, 'name' => 'E', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $this->actingAs($this->manager)->get(route('payroll.history.export', ['enterprise_id' => $autre->id]))->assertForbidden();
    }

    /** Lets a request build its PDF view's data without rendering a PDF: the last request's data. */
    private function capturePdf(): object
    {
        if ($this->capture) {
            return $this->capture;
        }
        $captured = $this->capture = (object) ['data' => []];
        $pdf = \Mockery::mock();
        $pdf->shouldReceive('setPaper')->andReturnSelf();
        $pdf->shouldReceive('download')->andReturn(response('pdf'));
        Pdf::shouldReceive('loadView')->andReturnUsing(function ($view, $data) use ($captured, $pdf) {
            $captured->data = $data;

            return $pdf;
        });

        return $captured;
    }
}
