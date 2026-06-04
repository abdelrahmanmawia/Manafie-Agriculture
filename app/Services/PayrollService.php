<?php

namespace App\Services;

class PayrollService
{
    /**
     * Calculate payroll based on Enterprise Contract Type
     * Profile 'avec_contrat': Includes CNSS, AMO, IR
     * Profile 'sans_contrat': Flat rate (Net = Gross)
     */
    public function calculate($contractType, $dailyRate, $hs = 0, $bonuses = 0, $advances = 0)
    {
        $hourlyRate = $dailyRate / 8;
        $hsPay = $hs * $hourlyRate;
        
        $brut = $dailyRate + $hsPay + $bonuses;
        
        $cnss = 0;
        $amo = 0;
        $ir = 0;

        if ($contractType === 'avec_contrat') {
            // Standard Moroccan rates
            $cnssRate = 0.0448; // 4.48%
            $amoRate = 0.0226;  // 2.26%
            
            // CNSS is capped at 6000 DH base
            $cnssBase = min($brut, 6000);
            $cnss = $cnssBase * $cnssRate;
            
            $amo = $brut * $amoRate;
            
            // IR Calculation on taxable base
            $ir = $this->calculateIR($brut - $cnss - $amo);
        }

        $net = $brut - $cnss - $amo - $ir - $advances;

        return [
            'brut' => $brut,
            'cnss' => $cnss,
            'amo' => $amo,
            'ir' => $ir,
            'net' => $net,
        ];
    }

    private function calculateIR($taxableBase)
    {
        if ($taxableBase <= 2500) {
            return 0;
        } elseif ($taxableBase <= 4166) {
            return ($taxableBase * 0.10) - 250;
        } elseif ($taxableBase <= 5000) {
            return ($taxableBase * 0.20) - 666.67;
        } else {
            return ($taxableBase * 0.30) - 1166.67;
        }
    }
}
