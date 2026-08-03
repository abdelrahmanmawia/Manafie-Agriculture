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
 * calculateInvoicing()'s figures below are read directly (not reconstructed by hand) from the real
 * source file's own cached formula results: storage/app/prs_archive/
 * "P-30062026 PERSEALAND-AGRI INTERIM xlsx.xlsx", sheet Feuil1, 2QZ Juin 2026, enterprise AGRI
 * INTERIM (brut=97.44).
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
        // CIN G696271 CHIKH KHALID: 13 days worked, 1 JF day, 0 H.S. hours, comp=3.27 (source's
        // own COMP column, not our seeded Employee.complement — that reflects whichever period
        // was chronologically latest for this CIN, which can differ from June's own value).
        $calc = $this->svc->calculateInvoicing('avec_contrat', 97.44, 3.27, 13, 1, 0, true);

        $this->assertEqualsWithDelta(171.994216512, $calc['net_factur_j'], 0.001);
        // A small (~0.003 out of ~2352) residual gap here comes from DEDUCTION_RATE (0.0674) being
        // a rounded constant rather than the spreadsheet's exact underlying ratio — pre-existing,
        // unrelated to the JF/H.S. formula bug this test suite covers; not tightened further.
        $this->assertEqualsWithDelta(2352.658414656, $calc['total_ttc'], 0.01);
    }

    public function test_invoicing_matches_real_spreadsheet_employee_with_overtime(): void
    {
        // CIN GM180174 EL-FAOUY OUTMANE: 15 days worked, 1 JF day, 46 H.S. hours, comp=18.27.
        $calc = $this->svc->calculateInvoicing('avec_contrat', 97.44, 18.27, 15, 1, 46, true);

        $this->assertEqualsWithDelta(237.344008512, $calc['net_factur_j'], 0.001);
        $this->assertEqualsWithDelta(3695.49372768, $calc['total_ttc'], 0.01);
    }

    public function test_invoicing_matches_real_spreadsheet_employee_with_no_jf_or_hs(): void
    {
        // CIN GG4096 EL GARADI MOHAMMED: 11 days worked, 0 JF days, 0 H.S. hours, comp≈0.
        $calc = $this->svc->calculateInvoicing('avec_contrat', 97.44, 0.0025440000000003, 11, 0, 0, true);

        $this->assertEqualsWithDelta(158.28734870907, $calc['net_factur_j'], 0.001);
        $this->assertEqualsWithDelta(1741.1608357998, $calc['total_ttc'], 0.01);
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
