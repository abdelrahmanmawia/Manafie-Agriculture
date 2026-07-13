<?php

namespace App\Exports;

use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\Employee;
use App\Models\Operation;
use App\Models\Bloc;
use App\Models\Enterprise; // Add this import
use App\Services\PayrollService;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle; // Add this import
use Carbon\CarbonPeriod;

class PointageExport implements FromView, ShouldAutoSize, WithTitle // Implement WithTitle
{
    protected $quinzaine; // Change to Quinzaine object
    protected $enterprise; // Add Enterprise object
    protected $payrollService;

    public function __construct(Quinzaine $quinzaine, Enterprise $enterprise) // Update constructor
    {
        $this->quinzaine = $quinzaine;
        $this->enterprise = $enterprise;
        $this->payrollService = new PayrollService();
    }

    public function view(): View
    {
        $quinzaine = $this->quinzaine;
        $enterprise = $this->enterprise;

        $employees = Employee::where('enterprise_id', $enterprise->id)->get(); // Filter by enterprise ID
        $operations = Operation::where('farm_id', $enterprise->farm_id)->get(); // Filter by enterprise's farm ID
        $blocs = Bloc::where('farm_id', $enterprise->farm_id)->get(); // Filter by enterprise's farm ID

        $period = CarbonPeriod::create($quinzaine->start_date, $quinzaine->end_date);
        $days = [];
        foreach ($period as $date) { $days[] = $date->format('Y-m-d'); }

        $records = PointageRecord::where('quinzaine_id', $quinzaine->id)
            ->whereHas('employee', function ($query) use ($enterprise) { // Filter records by employee's enterprise
                $query->where('enterprise_id', $enterprise->id);
            })
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
                $blocMatrices[$bloc->name][$op->abbreviation ?? $op->name] = array_fill_keys($days, 0);
            }
        }

        foreach ($records as $record) {
            $calc = $this->payrollService->calculate(
                $enterprise->contract_type, // Use enterprise's contract type
                $enterprise->default_brut_rate, // Use enterprise's default brut rate
                $record->hours,
                $record->employee->complement,
                $record->is_jf
            );
            $dateKey = $record->date->format('Y-m-d');
            $blocMatrices[$record->bloc->name][$record->operation->abbreviation ?? $record->operation->name][$dateKey] += $calc['total_net'];
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

    /**
     * @return string
     */
    public function title(): string
    {
        return $this->enterprise->name; // Set sheet title to enterprise name
    }
}
