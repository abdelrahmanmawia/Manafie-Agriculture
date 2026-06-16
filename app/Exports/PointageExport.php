<?php

namespace App\Exports;

use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\Employee;
use App\Models\Operation;
use App\Models\Bloc;
use App\Services\PayrollService;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Carbon\CarbonPeriod;

class PointageExport implements FromView, ShouldAutoSize
{
    protected $quinzaineId;
    protected $payrollService;

    public function __construct($quinzaineId)
    {
        $this->quinzaineId = $quinzaineId;
        $this->payrollService = new PayrollService();
    }

    public function view(): View
    {
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($this->quinzaineId);
        $employees = Employee::where('enterprise_id', $quinzaine->enterprise_id)->get();
        $operations = Operation::where('farm_id', $quinzaine->enterprise->farm_id)->get();
        $blocs = Bloc::where('farm_id', $quinzaine->enterprise->farm_id)->get();

        $period = CarbonPeriod::create($quinzaine->start_date, $quinzaine->end_date);
        $days = [];
        foreach ($period as $date) { $days[] = $date->format('Y-m-d'); }

        $records = PointageRecord::where('quinzaine_id', $this->quinzaineId)
            ->with(['employee', 'operation', 'bloc'])
            ->get();

        $groupedRecords = $records->groupBy(['employee_id', function ($item) {
            return $item->date->format('Y-m-d');
        }]);

        // Calculate Bloc Matrices
        $blocMatrices = [];
        foreach ($blocs as $bloc) {
            $blocMatrices[$bloc->name] = [];
            foreach ($operations as $op) {
                $blocMatrices[$bloc->name][$op->name] = array_fill_keys($days, 0);
            }
        }

        foreach ($records as $record) {
            $calc = $this->payrollService->calculate(
                $quinzaine->enterprise->contract_type,
                $quinzaine->enterprise->default_brut_rate,
                $record->hours,
                $record->employee->complement,
                $record->is_jf
            );
            $dateKey = $record->date->format('Y-m-d');
            $blocMatrices[$record->bloc->name][$record->operation->name][$dateKey] += $calc['total_net'];
        }

        return view('exports.pointage', [
            'quinzaine' => $quinzaine,
            'employees' => $employees,
            'days' => $days,
            'records' => $groupedRecords,
            'payrollService' => $this->payrollService,
            'operations' => $operations,
            'blocs' => $blocs,
            'blocMatrices' => $blocMatrices
        ]);
    }
}
