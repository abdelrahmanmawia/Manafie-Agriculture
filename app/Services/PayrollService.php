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
    const TAX_ADJUSTMENT = 1.04;   // (...) * 1.04, applies to both the base bracket and the JF/H.S. bracket
    const SERVICE_TAX = 1.2;       // (...) * 1.2 — applies to the WHOLE invoice bracket (base +
                                    // complement + hs/jf), not only the complement/hs portion; see
                                    // calculateInvoicing() below.
    const JF_TOTAL_TTC_MULTIPLIER = 1.24; // flat multiplier on a JF-day bonus's contribution to
                                    // TOTAL TTC — distinct from TAX_ADJUSTMENT*SERVICE_TAX (1.248),
                                    // per the real spreadsheet formula (see calculateInvoicing()).

    /**
     * Calculate a worker's own pay for a single day/record (brut, sal_net_j, hs_pay, total_net).
     *
     * Does NOT compute client-invoicing figures (net_factur_j/total_ttc) — those are period-level,
     * not per-day (see calculateInvoicing() below). An earlier version of this method attempted a
     * per-day approximation of them; it was wrong (missing the JF/H.S. period-spread term) and has
     * been removed rather than left as a second, incorrect source of the same numbers.
     *
     * @param bool $worked Whether the employee actually worked this day (has an operation/bloc).
     *                      A day can be flagged is_jf=true either way: worked+JF pays double
     *                      (the normal day rate PLUS the holiday bonus — the employee showed up on
     *                      a public holiday); a JF day the employee did NOT work still owes them
     *                      one single day's pay (a paid holiday), never the double. See
     *                      PointageController::updateCell() for how a "paid, unworked JF day"
     *                      record (operation_id/bloc_id both null) is created.
     */
    public function calculate($contractType, $brutRate, $hs = 0, $complement = 0, $isJf = false, $invoicedToClient = null, $worked = true)
    {
        // The overtime hourly rate is derived from this division's own standard daily net salary
        // (after the worker deduction, but before any complement/prime — never the raw brut rate,
        // and never inflated by a per-employee complement), divided by an 8h standard workday.
        $standardNetJ = $contractType === 'avec_contrat'
            ? $brutRate * (1 - self::DEDUCTION_RATE)
            : $brutRate;
        $hsHourlyRate = $standardNetJ / self::STANDARD_WORKDAY_HOURS;

        if ($contractType === 'avec_contrat') {
            // AGRIPER logic
            $salNetJ = $standardNetJ + $complement;
            $hsPay = $hs * $hsHourlyRate;
            // JF Pay (Jour Férié) - if active AND actually worked, worker gets another salNetJ
            // on top of the base day rate already in $salNetJ. An unworked paid JF day earns
            // only the base $salNetJ once — no bonus for a day nobody showed up to.
            $jfPay = ($isJf && $worked) ? $salNetJ : 0;
            $totalWorkerNet = $salNetJ + $hsPay + $jfPay;
        } else {
            // HAFILATY logic
            $salNetJ = $standardNetJ; // sal_net_j = sal_brut_j (no deduction for this contract type)
            $hsPay = $hs * $hsHourlyRate;
            $totalWorkerNet = $salNetJ + $hsPay;
        }

        return [
            'brut' => $brutRate,
            'sal_net_j' => $salNetJ,
            'hs_pay' => $hsPay,
            'total_net' => $totalWorkerNet,
        ];
    }

    /**
     * Client-invoicing figures (net_factur_j, total_ttc) for one employee's WHOLE quinzaine —
     * genuinely period-level, not derivable from a single day in isolation. Formula read directly
     * from the live Excel formula behind the source workbook's own NET FACTUR J/TOTAL TTC columns
     * (2eme Qz Aout 2026 P.L.xlsm, sheet A.I, cols AE/AF — a stronger source of truth than the
     * single-sample reverse-engineering this replaced, which had one bug this exposed: it did NOT
     * multiply comp by 1.0674 the way the worker-pay side does):
     *
     *   net_factur_j = ((brut*1.2109 + 5.62 + 3.22 + comp) * 1.04
     *                   + ((jfDays*brut + hsHours*brut/8) / totalDays) * 1.04) * 1.2
     *   total_ttc    = net_factur_j * totalDays + jfDays * sal_net_j * 1.24
     *
     * The JF/H.S. term inside net_factur_j spreads that period's total overtime/holiday
     * invoicing evenly across every day the employee worked — the earlier per-day `calculate()`
     * approach could never reproduce this since it doesn't know the period's totals.
     */
    public function calculateInvoicing($contractType, $brutRate, $complement, $totalDays, $jfDaysCount, $hsHoursTotal, $invoicedToClient = null)
    {
        if ($invoicedToClient === null) {
            $invoicedToClient = $contractType === 'avec_contrat';
        }

        if (!$invoicedToClient || $contractType !== 'avec_contrat' || $totalDays <= 0) {
            return ['net_factur_j' => 0, 'total_ttc' => 0];
        }

        $standardNetJ = $brutRate * (1 - self::DEDUCTION_RATE);
        $salNetJ = $standardNetJ + $complement;

        $baseBracket = $brutRate * self::CHARGE_RATE + self::FIXED_CHARGE_1 + self::FIXED_CHARGE_2
            + $complement;
        $jfHsBracket = ($jfDaysCount * $brutRate + $hsHoursTotal * $brutRate / self::STANDARD_WORKDAY_HOURS) / $totalDays;

        $netFacturJ = ($baseBracket + $jfHsBracket) * self::TAX_ADJUSTMENT * self::SERVICE_TAX;
        $totalTtc = $netFacturJ * $totalDays + $jfDaysCount * $salNetJ * self::JF_TOTAL_TTC_MULTIPLIER;

        return ['net_factur_j' => $netFacturJ, 'total_ttc' => $totalTtc];
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
        })->whereNull('quantity')->with('employee')->get()->each(function ($record) use ($enterprise) {
            $calc = $this->calculate(
                $enterprise->contract_type,
                $enterprise->default_brut_rate,
                $record->hours,
                $record->employee->complement,
                $record->is_jf,
                null,
                $record->operation_id !== null
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
            ->whereNull('quantity')
            ->get()
            ->each(function ($record) use ($employee) {
                $calc = $this->calculate(
                    $employee->enterprise->contract_type,
                    $employee->enterprise->default_brut_rate,
                    $record->hours,
                    $employee->complement,
                    $record->is_jf,
                    null,
                    $record->operation_id !== null
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
        // net_factur_j/total_ttc are period-level (see calculateInvoicing()), so each employee's
        // days-worked/JF-day/H.S.-hours totals for this whole quinzaine need collecting first.
        $employeeAggregates = [];

        foreach ($quinzaine->pointageRecords as $record) {
            // A record with no operation is a paid public holiday the employee did NOT work
            // (see PointageController::updateCell()) — pays a single day's rate, never the JF
            // double, and doesn't count as a worked/bonus day for the invoicing spread below.
            $worked = $record->operation_id !== null;

            $calc = $this->calculate(
                $quinzaine->enterprise->contract_type,
                $quinzaine->enterprise->default_brut_rate,
                $record->hours,
                $record->employee->complement,
                $record->is_jf,
                null,
                $worked
            );

            $totalNet += $calc['total_net'];
            $totalBrut += $calc['brut'];
            $totalHours += $record->hours;

            // Breakdown by Employee
            $employeeId = $record->employee_id;
            $employeeNets[$employeeId] = ($employeeNets[$employeeId] ?? 0) + $calc['total_net'];
            $employees[$employeeId] = $record->employee_id;

            $employeeAggregates[$employeeId] ??= ['days' => 0, 'jf' => 0, 'hs' => 0, 'complement' => $record->employee->complement];
            $employeeAggregates[$employeeId]['days']++;
            if ($record->is_jf && $worked) {
                $employeeAggregates[$employeeId]['jf']++;
            }
            $employeeAggregates[$employeeId]['hs'] += $record->hours;

            // Breakdown by Operation/Bloc — an unworked paid JF day has neither, so it's
            // deliberately left out of both breakdowns (its net is still in totalNet above).
            if ($record->operation) {
                $opName = $record->operation->name;
                $opCosts[$opName] = ($opCosts[$opName] ?? 0) + $calc['total_net'];
            }
            if ($record->bloc) {
                $blocName = $record->bloc->name;
                $blocCosts[$blocName] = ($blocCosts[$blocName] ?? 0) + $calc['total_net'];
            }
        }

        foreach ($employeeAggregates as $agg) {
            $invoicing = $this->calculateInvoicing(
                $quinzaine->enterprise->contract_type,
                $quinzaine->enterprise->default_brut_rate,
                $agg['complement'],
                $agg['days'],
                $agg['jf'],
                $agg['hs'],
                $quinzaine->enterprise->invoiced_to_client
            );
            $totalTtc += $invoicing['total_ttc'];
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
