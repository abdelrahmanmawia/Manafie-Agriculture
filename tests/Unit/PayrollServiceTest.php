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
}
