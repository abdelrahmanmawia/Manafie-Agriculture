<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use Illuminate\Support\Facades\DB;

/**
 * Fixed monthly salaries (employees.salaire_mensuel): paid once a month, in the second quinzaine
 * of the month (the one starting on the 16th), as a pointage record of nature "salaire_mensuel"
 * dated its last day — not present, no operation, net = the salary. Being a record, it is in every
 * total that sums the period's records (Grid, payslip, Excel fiche, payment sheet, closing
 * snapshot) without each of them knowing about it. The worker's day records pay nothing
 * (PointageRecordObserver). Only open quinzaines are touched: a closed one keeps what it paid.
 */
class SalaireFixeService
{
    public function __construct(private PayrollService $payroll) {}

    /**
     * Brings an employee's open quinzaines in line with their salary: the salary record in their
     * division's open second quinzaines (and nowhere else), and their day records repriced —
     * nothing while on a fixed salary, the normal day pay once it's removed.
     */
    public function syncEmployee(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            $open = Quinzaine::where('is_closed', false)->orderBy('id')->lockForUpdate()->get();

            PointageRecord::where('employee_id', $employee->id)->where('nature', PointageRecord::NATURE_SALAIRE)
                ->whereIn('quinzaine_id', $open->pluck('id'))
                ->when($employee->salaire_mensuel !== null, fn ($q) => $q->whereNotIn('quinzaine_id', $open->filter(fn ($qz) => $this->paysSalary($qz, $employee))->pluck('id')))
                ->get()->each->delete(); // one by one, for the change history

            if ($employee->salaire_mensuel !== null) {
                foreach ($open->filter(fn ($qz) => $this->paysSalary($qz, $employee)) as $quinzaine) {
                    $this->writeSalary($employee, $quinzaine);
                }
            }

            // Day records: the observer zeroes them while salaried; without a salary they go
            // back to the normal day pay.
            $this->payroll->recalculateOpenRecordsForEmployee($employee->fresh());
        });
    }

    /** A newly opened second quinzaine pays its division's salaried workers. */
    public function syncQuinzaine(Quinzaine $quinzaine): void
    {
        if ($quinzaine->is_closed || ! $this->isSecondHalf($quinzaine)) {
            return;
        }

        Employee::where('enterprise_id', $quinzaine->enterprise_id)->where('is_active', true)->whereNotNull('salaire_mensuel')
            ->get()->each(fn ($employee) => $this->writeSalary($employee, $quinzaine));
    }

    private function paysSalary(Quinzaine $quinzaine, Employee $employee): bool
    {
        return $quinzaine->enterprise_id === $employee->enterprise_id && $employee->is_active && $this->isSecondHalf($quinzaine);
    }

    private function isSecondHalf(Quinzaine $quinzaine): bool
    {
        return $quinzaine->start_date->day >= 16;
    }

    private function writeSalary(Employee $employee, Quinzaine $quinzaine): void
    {
        $values = [
            'present' => false, 'operation_id' => null, 'bloc_id' => null, 'hours' => 0, 'quantity' => null, 'is_jf' => false,
            'rate' => $employee->salaire_mensuel, 'brut' => 0, 'net' => $employee->salaire_mensuel, 'scan_uuid' => null,
        ];
        $existing = PointageRecord::where('employee_id', $employee->id)->where('quinzaine_id', $quinzaine->id)
            ->where('nature', PointageRecord::NATURE_SALAIRE)->first();

        $existing
            ? $existing->update($values)
            : PointageRecord::create($values + [
                'employee_id' => $employee->id, 'quinzaine_id' => $quinzaine->id,
                'date' => $quinzaine->end_date->toDateString(), 'nature' => PointageRecord::NATURE_SALAIRE,
            ]);
    }
}
