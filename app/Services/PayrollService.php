<?php

namespace App\Services;

class PayrollService
{
    /**
     * Constants based on user Excel formulas
     */
    const DEDUCTION_RATE = 0.0674; // (1 - 0.0674)
    const HS_HOURLY_RATE = 11.36;  // h_sup * 11.36
    const CHARGE_RATE = 1.2109;    // sal_brut_j * 1.2109
    const FIXED_CHARGE_1 = 5.37;   // + 5.37
    const FIXED_CHARGE_2 = 3.07;   // + 3.07
    const TAX_ADJUSTMENT = 1.04;   // (...) * 1.04
    const HS_INVOICE_RATE = 1.04;  // h_sup_unit * 1.04
    const SERVICE_TAX = 1.2;       // (...) * 1.2
    const JF_INVOICE_RATE = 1.24;  // jfsal_net_j * 1.24

    /**
     * Calculate worker pay and invoice details
     */
    public function calculate($contractType, $brutRate, $hs = 0, $complement = 0, $isJf = false)
    {
        // 1. Worker Pay Calculation
        if ($contractType === 'avec_contrat') {
            // AGRIPER logic
            $salNetJ = ($brutRate * (1 - self::DEDUCTION_RATE)) + $complement;
            
            // HS Pay
            $hsPay = $hs * self::HS_HOURLY_RATE;
            
            // JF Pay (Jour Férié) - if active, worker gets another salNetJ
            $jfPay = $isJf ? $salNetJ : 0;

            $totalWorkerNet = $salNetJ + $hsPay + $jfPay;

            // 2. Invoicing Logic (AGRIPER only)
            // net_factur_j = (sal_brut_j * 1.2109 + 5.37 + 3.07) * 1.04 + (comp + h_sup_unit * 1.04) * 1.2
            // Note: We calculate this per day (per record)
            $baseCharges = ($brutRate * self::CHARGE_RATE + self::FIXED_CHARGE_1 + self::FIXED_CHARGE_2) * self::TAX_ADJUSTMENT;
            $variableCharges = ($complement + ($hs > 0 ? self::HS_HOURLY_RATE : 0) * self::HS_INVOICE_RATE) * self::SERVICE_TAX;
            
            $netFacturJ = $baseCharges + $variableCharges;
            
            // total_ttc for JF: jfsal_net_j * 1.24
            $jfTtc = $isJf ? ($salNetJ * self::JF_INVOICE_RATE) : 0;
            
            $totalTtc = $netFacturJ + $jfTtc;

        } else {
            // HAFILATY logic
            $salNetJ = $brutRate; // sal_net_j = sal_brut_j
            $hsPay = $hs * self::HS_HOURLY_RATE;
            
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
                $record->is_jf
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
