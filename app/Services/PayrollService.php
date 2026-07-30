<?php

namespace App\Services;

class PayrollService
{
    /**
     * Constants based on user Excel formulas
     */
    const DEDUCTION_RATE = 0.0674; // (1 - 0.0674)
    const STANDARD_WORKDAY_HOURS = 8; // hs_hourly_rate = sal_brut_j / 8
    const CHARGE_RATE = 1.2109;    // sal_brut_j * 1.2109
    const FIXED_CHARGE_1 = 5.62;   // + 5.37
    const FIXED_CHARGE_2 = 3.22;   // + 3.07
    const TAX_ADJUSTMENT = 1.04;   // (...) * 1.04
    const HS_INVOICE_RATE = 1.04;  // h_sup_unit * 1.04
    const SERVICE_TAX = 1.2;       // (...) * 1.2 — applies to the WHOLE invoice bracket (base +
                                    // complement + hs/jf), not only the complement/hs portion; see
                                    // calculate() below.

    /**
     * Calculate worker pay and invoice details.
     *
     * $invoicedToClient controls the client-invoice figures (net_factur_j/total_ttc)
     * independently of $contractType: avec_contrat's CNSS-style worker deduction applies to any
     * division regardless of whether a client is actually invoiced for it (e.g. a farm's own
     * direct workforce still gets the deduction but has no client to bill, unlike an interim
     * placement agency). Defaults to the legacy behavior (invoiced iff avec_contrat) when not
     * passed explicitly, so any caller not yet updated to pass Enterprise::invoiced_to_client
     * keeps working as before.
     */
    public function calculate($contractType, $brutRate, $hs = 0, $complement = 0, $isJf = false, $invoicedToClient = null)
    {
        if ($invoicedToClient === null) {
            $invoicedToClient = $contractType === 'avec_contrat';
        }

        // The overtime hourly rate is derived from this division's own standard daily net salary
        // (after the worker deduction, but before any complement/prime — never the raw brut rate,
        // and never inflated by a per-employee complement), divided by an 8h standard workday.
        $standardNetJ = $contractType === 'avec_contrat'
            ? $brutRate * (1 - self::DEDUCTION_RATE)
            : $brutRate;
        $hsHourlyRate = $standardNetJ / self::STANDARD_WORKDAY_HOURS;

        // 1. Worker Pay Calculation
        if ($contractType === 'avec_contrat') {
            // AGRIPER logic
            $salNetJ = $standardNetJ + $complement;

            // HS Pay
            $hsPay = $hs * $hsHourlyRate;

            // JF Pay (Jour Férié) - if active, worker gets another salNetJ
            $jfPay = $isJf ? $salNetJ : 0;

            $totalWorkerNet = $salNetJ + $hsPay + $jfPay;

            // 2. Invoicing Logic — only for divisions that actually invoice a client (e.g. an
            // interim placement agency), independent of the worker-side deduction above. Formula
            // reverse-engineered from a real production spreadsheet (matched exactly against 85
            // real employees, see PR discussion):
            // net_factur_j = ((sal_brut_j * 1.2109 + 5.62 + 3.22 + comp * 1.0674) * 1.04
            //                 + (jf_amount + h_sup_unit * sal_brut_j / 8) * 1.04) * 1.2
            // Crucially, SERVICE_TAX (1.2) wraps the ENTIRE bracket — base charges included — not
            // just the complement/hs portion. The complement multiplier (1.0674) is exactly
            // (1 + DEDUCTION_RATE). The hs/jf invoice base rate uses the RAW brut hourly rate
            // (brut/8), unlike the worker's own hs_pay which uses the post-deduction rate.
            if ($invoicedToClient) {
                $netFacturJ = ($brutRate * self::CHARGE_RATE + self::FIXED_CHARGE_1 + self::FIXED_CHARGE_2
                    + $complement * (1 + self::DEDUCTION_RATE)) * self::TAX_ADJUSTMENT * self::SERVICE_TAX;

                $hsInvoiceCharge = $hs > 0
                    ? $hs * ($brutRate / self::STANDARD_WORKDAY_HOURS) * self::HS_INVOICE_RATE * self::SERVICE_TAX
                    : 0;
                $jfInvoiceCharge = $isJf ? ($salNetJ * self::TAX_ADJUSTMENT * self::SERVICE_TAX) : 0;

                $totalTtc = $netFacturJ + $hsInvoiceCharge + $jfInvoiceCharge;
            } else {
                $netFacturJ = 0;
                $totalTtc = 0;
            }

        } else {
            // HAFILATY logic
            $salNetJ = $standardNetJ; // sal_net_j = sal_brut_j (no deduction for this contract type)
            $hsPay = $hs * $hsHourlyRate;

            $totalWorkerNet = $salNetJ + $hsPay;

            $netFacturJ = 0; // No client invoicing mentioned for HAFILATY
            $totalTtc = 0;
        }

        return [
            'brut' => $brutRate,
            'sal_net_j' => $salNetJ,
            'hs_pay' => $hsPay,
            'total_net' => $totalWorkerNet,
            'net_factur_j' => $netFacturJ,
            'total_ttc' => $totalTtc
        ];
    }

    /**
     * Generate a snapshot of totals for a closed quinzaine
     */
    /**
     * PointageRecord.rate/brut/net are a denormalized snapshot of calculate()'s output, written
     * once by PointageController::updateCell() at the moment a cell is entered. If the
     * enterprise's default_brut_rate or contract_type changes afterward, every record entered
     * before that change is left stale — invisible until compared against a fresh calculate()
     * call (e.g. in a payslip PDF, which always recomputes live). Closed quinzaines are excluded:
     * their numbers are historically frozen in QuinzaineSummary and must not be rewritten.
     */
    public function recalculateOpenRecordsForEnterprise(\App\Models\Enterprise $enterprise): void
    {
        \App\Models\PointageRecord::whereHas('quinzaine', function ($q) use ($enterprise) {
            $q->where('enterprise_id', $enterprise->id)->where('is_closed', false);
        })->with('employee')->get()->each(function ($record) use ($enterprise) {
            $calc = $this->calculate(
                $enterprise->contract_type,
                $enterprise->default_brut_rate,
                $record->hours,
                $record->employee->complement,
                $record->is_jf
            );

            $record->update([
                'rate' => $enterprise->default_brut_rate,
                'brut' => $calc['brut'],
                'net' => $calc['total_net'],
            ]);
        });
    }

    /**
     * Same staleness problem as recalculateOpenRecordsForEnterprise(), triggered by an edit to
     * a single employee's complement instead of the enterprise's rate.
     */
    public function recalculateOpenRecordsForEmployee(\App\Models\Employee $employee): void
    {
        $employee->loadMissing('enterprise');

        \App\Models\PointageRecord::where('employee_id', $employee->id)
            ->whereHas('quinzaine', fn($q) => $q->where('is_closed', false))
            ->get()
            ->each(function ($record) use ($employee) {
                $calc = $this->calculate(
                    $employee->enterprise->contract_type,
                    $employee->enterprise->default_brut_rate,
                    $record->hours,
                    $employee->complement,
                    $record->is_jf
                );

                $record->update([
                    'rate' => $employee->enterprise->default_brut_rate,
                    'brut' => $calc['brut'],
                    'net' => $calc['total_net'],
                ]);
            });
    }

    public function generateSnapshot(\App\Models\Quinzaine $quinzaine)
    {
        $quinzaine->load(['enterprise', 'pointageRecords.employee', 'pointageRecords.operation', 'pointageRecords.bloc']);
        
        $totalNet = 0;
        $totalTtc = 0;
        $totalBrut = 0;
        $totalHours = 0;
        $employeeNets = [];
        $opCosts = [];
        $blocCosts = [];
        $employees = [];

        foreach ($quinzaine->pointageRecords as $record) {
            $calc = $this->calculate(
                $quinzaine->enterprise->contract_type,
                $quinzaine->enterprise->default_brut_rate,
                $record->hours,
                $record->employee->complement,
                $record->is_jf,
                $quinzaine->enterprise->invoiced_to_client
            );

            $totalNet += $calc['total_net'];
            $totalTtc += $calc['total_ttc'];
            $totalBrut += $calc['brut'];
            $totalHours += $record->hours;

            // Breakdown by Employee
            $employeeId = $record->employee_id;
            $employeeNets[$employeeId] = ($employeeNets[$employeeId] ?? 0) + $calc['total_net'];
            $employees[$employeeId] = $record->employee_id;

            // Breakdown by Operation
            $opName = $record->operation->name;
            $opCosts[$opName] = ($opCosts[$opName] ?? 0) + $calc['total_net'];

            // Breakdown by Bloc
            $blocName = $record->bloc->name;
            $blocCosts[$blocName] = ($blocCosts[$blocName] ?? 0) + $calc['total_net'];
        }

        // Format opCosts and blocCosts for Analytics-like output
        $formattedOpCosts = [];
        foreach ($opCosts as $name => $total) {
            $formattedOpCosts[] = ['name' => $name, 'total_net' => $total];
        }
        usort($formattedOpCosts, fn($a, $b) => $b['total_net'] <=> $a['total_net']);

        $formattedBlocCosts = [];
        foreach ($blocCosts as $name => $total) {
            $formattedBlocCosts[] = ['name' => $name, 'total_net' => $total];
        }

        return \App\Models\QuinzaineSummary::updateOrCreate(
            ['quinzaine_id' => $quinzaine->id],
            [
                'total_net' => $totalNet,
                'total_ttc' => $totalTtc,
                'total_brut' => $totalBrut,
                'total_hours' => $totalHours,
                'employee_count' => count($employees),
                'summary_data' => [
                    'employee_nets' => $employeeNets,
                    'op_costs' => $formattedOpCosts,
                    'bloc_costs' => $formattedBlocCosts,
                ]
            ]
        );
    }
}
