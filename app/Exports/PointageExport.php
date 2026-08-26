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

        // Only export employees who actually worked at least one day this period — an unworked
        // paid holiday (operation_id/bloc_id both null) or zero records at all doesn't count.
        $workedEmployeeIds = $records
            ->filter(fn ($r) => $r->operation_id !== null && $r->bloc_id !== null)
            ->pluck('employee_id')
            ->unique();
        $employees = $employees->filter(fn ($e) => $workedEmployeeIds->contains($e->id))->values();

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

        // A record with no operation/bloc is a paid public holiday the employee did NOT work
        // (see PointageController::updateCell()) — it has no real bloc/operation to sit in the
        // matrix above, but its net still counts toward TOTAL NET, so it needs its own line
        // rather than being silently dropped (which used to make the bloc tables' sum fall short
        // of TOTAL NET by exactly this amount).
        $jfUnworkedByDay = array_fill_keys($days, 0);

        foreach ($records as $record) {
            // Read the record's own stored net directly rather than recomputing via calculate()
            // — recomputing would use the employee's CURRENT complement/rate instead of what was
            // actually in effect when this day was entered, silently drifting the bloc/operation
            // tables away from what was really paid (and, for piece-rate records, calculate()
            // ignores quantity entirely and is simply wrong). Same fix already applied to
            // PointageController::summary() and this table's own TOTAL NET column.
            $dateKey = $record->date->format('Y-m-d');
            if ($record->operation_id === null) {
                $jfUnworkedByDay[$dateKey] += $record->net;
                continue;
            }
            $blocMatrices[$record->bloc->name][$record->operation->abbreviation ?? $record->operation->name][$dateKey] += $record->net;
        }

        return view('exports.pointage', [
            'quinzaine' => $quinzaine,
            'employees' => $employees,
            'days' => $days,
            'records' => $groupedRecords,
            'payrollService' => $this->payrollService,
            'jfUnworkedByDay' => $jfUnworkedByDay,
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
