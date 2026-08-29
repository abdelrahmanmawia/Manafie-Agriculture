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
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle; // Add this import
use Carbon\CarbonPeriod;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class PointageExport implements FromView, ShouldAutoSize, WithColumnWidths, WithTitle // Implement WithTitle
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

        // For a division that invoices a client, the bloc/operation tables represent billable
        // labor, not payroll — they use each employee's own net_factur_j (a flat per-day invoice
        // rate covering their WHOLE period, per PayrollService::calculateInvoicing()) instead of
        // their real net pay. Same fallback as calculateInvoicing()/the main table's own gate.
        $showInvoicing = $enterprise->invoiced_to_client ?? ($enterprise->contract_type === 'avec_contrat');

        // Per-employee net_factur_j and JF-in-TTC-bonus, computed once per employee from their
        // WHOLE period (quantity/piece-rate days excluded — same inputs calculateInvoicing()
        // already needs) rather than per record, since net_factur_j isn't a per-day figure.
        $employeeInvoicing = [];
        if ($showInvoicing) {
            $plainByEmployee = $records->filter(fn ($r) => $r->quantity === null)->groupBy('employee_id');
            foreach ($plainByEmployee as $employeeId => $empRecords) {
                $totalDays = $empRecords->filter(fn ($r) => $r->operation_id !== null && $r->bloc_id !== null)->count();
                $jfCount = $empRecords->filter(fn ($r) => $r->is_jf)->count();
                $hsTotal = $empRecords->sum('hours');
                $empRate = $empRecords->first()->rate;
                $employeeComplement = $empRecords->first()->employee->complement;

                $invoicing = $this->payrollService->calculateInvoicing(
                    $enterprise->contract_type, $empRate, $employeeComplement, $totalDays, $jfCount, $hsTotal, $enterprise->invoiced_to_client
                );
                $calc = $this->payrollService->calculate(
                    $enterprise->contract_type, $empRate, 0, $employeeComplement, false, $enterprise->invoiced_to_client, true
                );

                $employeeInvoicing[$employeeId] = [
                    'net_factur_j' => $invoicing['net_factur_j'],
                    // The JF bonus in TOTAL TTC (jfDays * salNetJ * multiplier) is a flat amount
                    // PER JF day, added on top of net_factur_j*totalDays — not itself part of
                    // net_factur_j, and owed whether or not that specific JF day was worked.
                    'jf_bonus_per_day' => $calc['sal_net_j'] * PayrollService::JF_TOTAL_TTC_MULTIPLIER,
                ];
            }
        }

        // A record with no operation/bloc is a paid public holiday the employee did NOT work
        // (see PointageController::updateCell()) — it has no real bloc/operation to sit in the
        // matrix above, but its net (or, in invoicing mode, its JF billing bonus) still counts
        // toward the period total, so it needs its own line rather than being silently dropped.
        $jfUnworkedByDay = array_fill_keys($days, 0);

        foreach ($records as $record) {
            $dateKey = $record->date->format('Y-m-d');

            if ($showInvoicing && $record->quantity === null) {
                $inv = $employeeInvoicing[$record->employee_id] ?? null;
                $jfBonus = ($record->is_jf && $inv) ? $inv['jf_bonus_per_day'] : 0;

                if ($record->operation_id === null) {
                    // Unworked JF: only the bonus applies — this day isn't in totalDays, so it
                    // has no net_factur_j contribution of its own (matches calculateInvoicing()'s
                    // own total_ttc formula exactly).
                    $jfUnworkedByDay[$dateKey] += $jfBonus;
                    continue;
                }

                $blocMatrices[$record->bloc->name][$record->operation->abbreviation ?? $record->operation->name][$dateKey]
                    += ($inv['net_factur_j'] ?? 0) + $jfBonus;
                continue;
            }

            // Non-invoicing mode, or a piece-rate record (no client-invoicing formula exists for
            // piece-rate work in this system) — read the record's own stored net directly rather
            // than recomputing via calculate(), which would use the employee's CURRENT
            // complement/rate instead of what was actually in effect when this day was entered.
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
            'blocMatrices' => $blocMatrices,
            'blocMatricesShowInvoicing' => $showInvoicing,
        ]);
    }

    /**
     * @return string
     */
    public function title(): string
    {
        return $this->enterprise->name; // Set sheet title to enterprise name
    }

    /**
     * ShouldAutoSize sizes every column from its own cell content, but the title/period/SOMME NET
     * rows above the header span the whole table width via colspan — PhpSpreadsheet's HTML reader
     * attributes that merged text to column A when computing widths, wildly inflating N° (a 3-4
     * digit matricule) to ~30 units. TOTAL J suffers the same thing from its own header text (its
     * real values are 1-2 digits). Both are pinned to a narrow, explicit width here instead;
     * every other column is left to ShouldAutoSize.
     */
    public function columnWidths(): array
    {
        $showInvoicing = $this->enterprise->invoiced_to_client
            ?? ($this->enterprise->contract_type === 'avec_contrat');
        $dayCount = iterator_count(CarbonPeriod::create($this->quinzaine->start_date, $this->quinzaine->end_date));

        // Column order: N°, NOM, PRENOM, CIN, RIB, <days...>, SAL NET/J, SAL BRUT/J, [MARGE],
        // TOTAL J, J.F CH (DH), H.S, TOTAL NET, [NET FACTUR J, TOTAL TTC] — see pointage.blade.php.
        $totalJColumn = 5 + $dayCount + 2 + ($showInvoicing ? 1 : 0) + 1;

        return [
            'A' => 6,
            Coordinate::stringFromColumnIndex($totalJColumn) => 6,
        ];
    }
}
