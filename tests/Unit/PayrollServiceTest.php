<?php

namespace Tests\Unit;

use App\Services\PayrollService;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for PayrollService::calculate() (per-day worker pay) and
 * PayrollService::calculateInvoicing() (period-level client invoicing).
 *
 * calculate() used to also return net_factur_j/total_ttc, approximating them per day — but the
 * real formula needs each employee's WHOLE quinzaine (total days worked, JF-day count, H.S. hours
 * total), not just one record, to spread the period's JF/overtime invoicing evenly across every
 * worked day. That approximation was silently wrong for any employee with JF days or overtime, so
 * invoicing was split into its own period-aware method instead of left half-correct on calculate().
 *
 * calculateInvoicing()'s figures below are read directly (not reconstructed by hand) from each
 * real source file's own live Excel formula for NET FACTUR J/TOTAL TTC — Juillet/Août 2026
 * (sheet A.I, enterprise AGRI INTERIM, brut=97.44), NOT Juin 2026: Juin's own workbook (P-30062026
 * PERSEALAND-AGRI INTERIM xlsx.xlsx) multiplies COMP by 1.0674 in this formula
 * (`+(COMP*1.0674))*1.04`) where every period since Juillet does not (`+COMP)*1.04`) — confirmed by
 * reading the literal formula text out of all of Juin/1re+2eme Qz Juillet/2eme Qz Août's workbooks.
 * Juin's figures were computed under a formula the business itself has since dropped, so they're
 * not a valid reference for the CURRENT formula below — these tests use Juillet/Août figures only.
 */
class PayrollServiceTest extends TestCase
{
    private PayrollService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PayrollService();
    }

    public function test_avec_contrat_normal_day_worker_pay(): void
    {
        // CIN G696271 CHIKH KHALID: brut=97.44, comp=3.27 (real source's own COMP column value).
        $calc = $this->svc->calculate('avec_contrat', 97.44, 0, 3.27, false);

        // Same ~0.0025 DEDUCTION_RATE rounding gap as the invoicing tests above (0.0674 is a
        // rounded constant, not the spreadsheet's exact ratio) — pre-existing, not tightened here.
        $this->assertEqualsWithDelta(94.14, $calc['sal_net_j'], 0.01);
        $this->assertEqualsWithDelta(94.14, $calc['total_net'], 0.01);
        $this->assertArrayNotHasKey('net_factur_j', $calc);
        $this->assertArrayNotHasKey('total_ttc', $calc);
    }

    public function test_avec_contrat_jour_ferie_adds_a_second_sal_net_j(): void
    {
        $calc = $this->svc->calculate('avec_contrat', 97.44, 0, 3.27, true);

        $this->assertEqualsWithDelta(94.14 * 2, $calc['total_net'], 0.01);
    }

    public function test_avec_contrat_overtime_uses_post_deduction_hourly_rate(): void
    {
        $calc = $this->svc->calculate('avec_contrat', 97.44, 46, 18.267456, false);

        $hsHourlyRate = (97.44 * (1 - PayrollService::DEDUCTION_RATE)) / PayrollService::STANDARD_WORKDAY_HOURS;
        $this->assertEqualsWithDelta($hsHourlyRate * 46, $calc['hs_pay'], 0.0001);
    }

    public function test_sans_contrat_no_deduction_and_no_complement(): void
    {
        $calc = $this->svc->calculate('sans_contrat', 90.87, 5, 20, false);

        // sans_contrat passes brut straight through — no 6.74% deduction, no complement added.
        $this->assertEqualsWithDelta(90.87, $calc['sal_net_j'], 0.0001);
    }

    public function test_invoicing_matches_real_spreadsheet_normal_employee(): void
    {
        // CIN G696271 CHIKH KHALID, 2eme Qz Août 2026, sheet A.I: 10 days worked, 3 JF days,
        // 0 H.S. hours, comp=3.267456 (source's own COMP column for this specific period).
        $calc = $this->svc->calculateInvoicing('avec_contrat', 97.44, 3.267456, 10, 3, 0, true);

        $this->assertEqualsWithDelta(198.843280896, $calc['net_factur_j'], 0.0001);
        $this->assertEqualsWithDelta(2338.633608960, $calc['total_ttc'], 0.0001);
    }

    public function test_invoicing_matches_real_spreadsheet_employee_with_overtime(): void
    {
        // CIN GM180174 EL-FAOUY OUTMANE, 2eme Qz Août 2026, sheet A.I: 7 days worked, 0 JF days,
        // 28 H.S. hours, comp=18.267456.
        $calc = $this->svc->calculateInvoicing('avec_contrat', 97.44, 18.267456, 7, 0, 28, true);

        $this->assertEqualsWithDelta(241.884304896, $calc['net_factur_j'], 0.0001);
        $this->assertEqualsWithDelta(1693.190134272, $calc['total_ttc'], 0.0001);
    }

    public function test_invoicing_matches_real_spreadsheet_employee_with_no_jf_or_hs(): void
    {
        // CIN G546734 AHANNI AZIZA, 2eme Qz Juillet 2026, sheet A.I: 9 days worked, 0 JF days,
        // 0 H.S. hours, comp=0.
        $calc = $this->svc->calculateInvoicing('avec_contrat', 97.44, 0, 9, 0, 0, true);

        $this->assertEqualsWithDelta(158.283959808, $calc['net_factur_j'], 0.0001);
        $this->assertEqualsWithDelta(1424.555638272, $calc['total_ttc'], 0.0001);
    }

    public function test_sans_contrat_never_invoices_a_client(): void
    {
        $calc = $this->svc->calculateInvoicing('sans_contrat', 90.87, 20, 10, 1, 5, true);

        $this->assertEquals(0, $calc['net_factur_j']);
        $this->assertEquals(0, $calc['total_ttc']);
    }

    public function test_avec_contrat_with_invoiced_to_client_false_never_invoices(): void
    {
        // The real Persealand division: avec_contrat (deduction applies) but no client to invoice,
        // unlike an interim placement agency (Agri Interim).
        $calc = $this->svc->calculateInvoicing('avec_contrat', 97.44, 15, 10, 1, 5, false);

        $this->assertEquals(0, $calc['net_factur_j']);
        $this->assertEquals(0, $calc['total_ttc']);
    }

    public function test_calculate_invoicing_defaults_to_avec_contrat_when_flag_omitted(): void
    {
        // Backward-compatible default for any caller not yet passing the explicit flag.
        $calc = $this->svc->calculateInvoicing('avec_contrat', 97.44, 0, 10, 0, 0);
        $this->assertGreaterThan(0, $calc['net_factur_j']);

        $calc = $this->svc->calculateInvoicing('sans_contrat', 90.87, 0, 10, 0, 0);
        $this->assertEquals(0, $calc['net_factur_j']);
    }

    public function test_calculate_invoicing_returns_zero_when_no_days_worked(): void
    {
        $calc = $this->svc->calculateInvoicing('avec_contrat', 97.44, 0, 0, 0, 0, true);

        $this->assertEquals(0, $calc['net_factur_j']);
        $this->assertEquals(0, $calc['total_ttc']);
    }

    // --- payslipBreakdown() ---

    /** A record with its net computed the way PointageController stores it. */
    private function dayRecord(string $contract, float $rate, float $complement, array $attrs = []): object
    {
        $attrs += ['hours' => 0, 'is_jf' => false, 'operation_id' => 1, 'quantity' => null];
        $calc = $this->svc->calculate($contract, $rate, $attrs['hours'], $complement, $attrs['is_jf'], null, $attrs['operation_id'] !== null);

        return (object) ($attrs + ['rate' => $rate, 'net' => $calc['total_net']]);
    }

    public function test_payslip_gains_minus_retenues_equals_net_for_avec_contrat(): void
    {
        $records = collect([
            $this->dayRecord('avec_contrat', 100, 10, ['hours' => 2]),
            $this->dayRecord('avec_contrat', 100, 10, ['is_jf' => true]),                        // worked JF
            $this->dayRecord('avec_contrat', 100, 10, ['is_jf' => true, 'operation_id' => null]), // unworked JF
            (object) ['rate' => 5, 'hours' => 0, 'is_jf' => false, 'operation_id' => 1, 'quantity' => 40, 'net' => 200], // piece-rate
        ]);

        $p = $this->svc->payslipBreakdown('avec_contrat', $records, 10);

        $this->assertSame(3, $p['days']);
        $this->assertSame(1, $p['jf_days']);           // unworked JF is not a bonus day
        $this->assertSame(1, $p['piece_days']);
        $this->assertEqualsWithDelta(300, $p['base'], 0.001);
        $this->assertEqualsWithDelta(200, $p['piece_pay'], 0.001);
        $this->assertEqualsWithDelta(300 * PayrollService::DEDUCTION_RATE, $p['total_retenues'], 0.001);
        $this->assertEqualsWithDelta($p['total_net'], $p['total_gains'] - $p['total_retenues'], 0.001);
    }

    public function test_payslip_sans_contrat_has_no_deduction_jf_bonus_or_complement(): void
    {
        $records = collect([
            $this->dayRecord('sans_contrat', 90, 10, ['hours' => 1]),
            $this->dayRecord('sans_contrat', 90, 10, ['is_jf' => true]),
        ]);

        $p = $this->svc->payslipBreakdown('sans_contrat', $records, 10);

        $this->assertEquals(0, $p['total_retenues']);
        $this->assertEquals(0, $p['jf_pay']);
        $this->assertEquals(0, $p['complement_pay']);
        $this->assertEqualsWithDelta($p['total_net'], $p['total_gains'], 0.001);
    }

    public function test_payslip_uses_each_records_own_rate(): void
    {
        // Rate changed mid-period and the record was frozen at the old one — the payslip must
        // follow the record, not whatever rate is current.
        $records = collect([
            $this->dayRecord('avec_contrat', 100, 0),
            $this->dayRecord('avec_contrat', 120, 0),
        ]);

        $p = $this->svc->payslipBreakdown('avec_contrat', $records, 0);

        $this->assertEqualsWithDelta(220, $p['base'], 0.001);
        $this->assertEqualsWithDelta($p['total_net'], $p['total_gains'] - $p['total_retenues'], 0.001);
    }

    // --- October 2026 formula (P-15102026 PERSEALAND AGRI INTERIM.xlsx, cols AC/AD) ---

    public function test_october_2026_formula_matches_the_reference_workbook(): void
    {
        // CIN G445076 SAYEH SAIDA, 1re Qz octobre 2026: NET/J 110 (comp 19.13), 3 days, no JF/H.S.
        // 183.77 / 551.30 are the values Excel itself displays for this row.
        $calc = $this->svc->calculateInvoicing('avec_contrat', 97.44, 19.13, 3, 0, 0, true, '2026-10-01');

        $this->assertEqualsWithDelta(183.77, $calc['net_factur_j'], 0.005);
        $this->assertEqualsWithDelta(551.30, $calc['total_ttc'], 0.005);
    }

    public function test_october_2026_formula_with_overtime(): void
    {
        // CIN GM180174, same workbook: NET/J 109 (comp 18.13), 15 days, 60 H.S. hours — expected
        // values evaluated from that row's own AC/AD formulas.
        $calc = $this->svc->calculateInvoicing('avec_contrat', 97.44, 18.13, 15, 0, 60, true, '2026-10-01');

        $this->assertEqualsWithDelta(243.2378, $calc['net_factur_j'], 0.0001);
        $this->assertEqualsWithDelta(3648.5665, $calc['total_ttc'], 0.0001);
    }

    public function test_october_2026_formula_invoices_jf_like_an_hs_hour_with_no_ttc_bonus(): void
    {
        // (Z+AA)*W/8/Y: one JF day weighs the same as one H.S. hour, and AD = AC*Y only.
        $withJf = $this->svc->calculateInvoicing('avec_contrat', 97.44, 19.13, 10, 1, 0, true, '2026-10-16');
        $withHsHour = $this->svc->calculateInvoicing('avec_contrat', 97.44, 19.13, 10, 0, 1, true, '2026-10-16');

        $this->assertEqualsWithDelta($withHsHour['net_factur_j'], $withJf['net_factur_j'], 1e-9);
        $this->assertEqualsWithDelta($withJf['net_factur_j'] * 10, $withJf['total_ttc'], 1e-9);
    }

    public function test_periods_before_october_2026_keep_the_previous_formula(): void
    {
        // Same inputs as the 2eme Qz Août spreadsheet test above, dated in late September.
        $calc = $this->svc->calculateInvoicing('avec_contrat', 97.44, 3.267456, 10, 3, 0, true, '2026-09-16');

        $this->assertEqualsWithDelta(198.843280896, $calc['net_factur_j'], 0.0001);
        $this->assertEqualsWithDelta(2338.633608960, $calc['total_ttc'], 0.0001);
        $this->assertTrue($this->svc->invoicesJfBonus('2026-09-16'));
        $this->assertFalse($this->svc->invoicesJfBonus('2026-10-01'));
    }

    // --- invoicingForRecords(): TOTAL J = worked days, JF = every JF day (business rule) ---

    public function test_invoicing_counts_worked_days_only_and_every_jf_day(): void
    {
        $worked = fn (array $a = []) => (object) ($a + ['rate' => 97.44, 'hours' => 0, 'is_jf' => false, 'operation_id' => 1, 'bloc_id' => 1, 'quantity' => null]);
        $records = collect(array_merge(
            array_fill(0, 9, $worked()),                                      // 9 normal worked days
            [$worked(['is_jf' => true, 'hours' => 2])],                       // 1 worked JF day (+2 H.S.)
            array_fill(0, 2, $worked(['is_jf' => true, 'operation_id' => null, 'bloc_id' => null])), // 2 unworked JF days
            [$worked(['rate' => 5, 'quantity' => 40])],                       // piece-rate: ignored
        ));

        $inv = $this->svc->invoicingForRecords('avec_contrat', $records, 3.267456, true);

        $this->assertSame(10, $inv['days']); // the worked JF counts, the unworked ones don't
        $this->assertSame(3, $inv['jf']);    // every JF day, worked or not
        $this->assertEquals(2, $inv['hs']);
        $this->assertEquals(97.44, $inv['rate']);
        $expected = $this->svc->calculateInvoicing('avec_contrat', 97.44, 3.267456, 10, 3, 2, true);
        $this->assertEqualsWithDelta($expected['total_ttc'], $inv['total_ttc'], 1e-9);
    }

    public function test_invoicing_for_a_worker_with_only_piece_rate_days_is_zero(): void
    {
        $records = collect([(object) ['rate' => 5, 'hours' => 0, 'is_jf' => false, 'operation_id' => 1, 'bloc_id' => 1, 'quantity' => 40]]);

        $inv = $this->svc->invoicingForRecords('avec_contrat', $records, 0, true);

        $this->assertEquals(0, $inv['total_ttc']);
    }

    public function test_cnss_and_amo_split_exactly_the_deduction_rate(): void
    {
        $this->assertEqualsWithDelta(PayrollService::DEDUCTION_RATE, PayrollService::CNSS_RATE + PayrollService::AMO_RATE, 1e-9);
    }
}
