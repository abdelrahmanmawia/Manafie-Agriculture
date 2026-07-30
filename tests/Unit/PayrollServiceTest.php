<?php

namespace Tests\Unit;

use App\Services\PayrollService;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for PayrollService::calculate()'s avec_contrat invoicing formula
 * (net_factur_j/total_ttc), reverse-engineered from a real production spreadsheet
 * (P-15072026 AGRI INTERIM.xlsx, "1er Quinzaine JUILLET 2026", enterprise AGRI INTERIM,
 * brut=97.44) and verified to match its real computed figures exactly across all 85 real
 * employees in that sheet. Three bugs were fixed here: SERVICE_TAX (x1.2) was missing from
 * the base charges term, the complement invoice multiplier was 1.2 instead of the real
 * 1.0674*1.04*1.2, and the overtime invoice base rate used the discounted net/8 rate instead
 * of the real raw brut/8 rate.
 */
class PayrollServiceTest extends TestCase
{
    private PayrollService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PayrollService();
    }

    public function test_avec_contrat_normal_day_matches_real_spreadsheet(): void
    {
        // Matricule 039 AHANNI AZIZA, a normal day with no complement/overtime/holiday.
        $calc = $this->svc->calculate('avec_contrat', 97.44, 0, 0, false);

        $this->assertEqualsWithDelta(90.872544, $calc['sal_net_j'], 0.0001);
        $this->assertEqualsWithDelta(90.872544, $calc['total_net'], 0.0001);
        $this->assertEqualsWithDelta(158.283959808, $calc['net_factur_j'], 0.0001);
        $this->assertEqualsWithDelta(158.283959808, $calc['total_ttc'], 0.0001);
    }

    public function test_avec_contrat_with_complement_and_overtime_matches_real_spreadsheet(): void
    {
        // Matricule 091 EL-GHRISSI, complement=18.27, this day carrying the period's 61 hs hours.
        $calc = $this->svc->calculate('avec_contrat', 97.44, 61, 18.27, false);

        $this->assertEqualsWithDelta(109.142544, $calc['sal_net_j'], 0.0001);
        $this->assertEqualsWithDelta(802.045692, $calc['total_net'], 0.0001);
        $this->assertEqualsWithDelta(182.621704512, $calc['net_factur_j'], 0.0001);
        $this->assertEqualsWithDelta(1109.860744512, $calc['total_ttc'], 0.0001);
    }

    public function test_avec_contrat_jour_ferie_doubles_pay_and_invoice(): void
    {
        $calc = $this->svc->calculate('avec_contrat', 97.44, 0, 0, true);

        // JF pay is a second full sal_net_j on top of the normal day.
        $this->assertEqualsWithDelta(181.745088, $calc['total_net'], 0.0001);
        $this->assertEqualsWithDelta(271.69289472, $calc['total_ttc'], 0.0001);
    }

    public function test_real_employee_period_total_matches_spreadsheet_grand_total(): void
    {
        // Matricule 091 EL-GHRISSI, full 1er Quinzaine Juillet 2026 period: 15 days present,
        // complement=18.27, 61 hs hours all attributed to one day. Real spreadsheet:
        // AC (total_net) = 2330.06, AE (total_ttc) = 3666.56.
        $normalDay = $this->svc->calculate('avec_contrat', 97.44, 0, 18.27, false);
        $hsDay = $this->svc->calculate('avec_contrat', 97.44, 61, 18.27, false);

        $totalNet = 14 * $normalDay['total_net'] + $hsDay['total_net'];
        $totalTtc = 14 * $normalDay['total_ttc'] + $hsDay['total_ttc'];

        $this->assertEqualsWithDelta(2330.06, $totalNet, 0.05);
        $this->assertEqualsWithDelta(3666.56, $totalTtc, 0.05);
    }

    public function test_sans_contrat_never_invoices_a_client(): void
    {
        $calc = $this->svc->calculate('sans_contrat', 90.87, 5, 20, true);

        $this->assertEquals(0, $calc['net_factur_j']);
        $this->assertEquals(0, $calc['total_ttc']);
    }

    public function test_avec_contrat_with_invoiced_to_client_false_still_deducts_but_never_invoices(): void
    {
        // The real Persealand division: avec_contrat (CNSS-style deduction applies) but no
        // client to invoice, unlike an interim placement agency (Agri Interim).
        $calc = $this->svc->calculate('avec_contrat', 97.44, 5, 15, true, false);

        $this->assertEqualsWithDelta(105.872544, $calc['sal_net_j'], 0.0001);
        $this->assertGreaterThan(0, $calc['total_net']);
        $this->assertEquals(0, $calc['net_factur_j']);
        $this->assertEquals(0, $calc['total_ttc']);
    }

    public function test_calculate_defaults_invoicing_to_avec_contrat_when_flag_omitted(): void
    {
        // Backward-compatible default for any caller not yet passing the explicit flag.
        $calc = $this->svc->calculate('avec_contrat', 97.44, 0, 0, false);
        $this->assertGreaterThan(0, $calc['net_factur_j']);

        $calc = $this->svc->calculate('sans_contrat', 90.87, 0, 0, false);
        $this->assertEquals(0, $calc['net_factur_j']);
    }
}
