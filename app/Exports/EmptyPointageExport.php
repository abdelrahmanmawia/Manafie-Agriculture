<?php

namespace App\Exports;

use App\Models\Enterprise;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;
use Carbon\Carbon;

class EmptyPointageExport implements FromView, ShouldAutoSize, WithTitle
{
    protected $enterprise;
    protected $startDate;
    protected $endDate;

    public function __construct(Enterprise $enterprise, $startDate, $endDate)
    {
        $this->enterprise = $enterprise;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function view(): View
    {
        // Return a view that represents an empty pointage sheet
        // You might want to create a specific Blade view for this, e.g., 'exports.empty_pointage'
        // For now, we can pass minimal data to the existing 'exports.pointage' view,
        // which should gracefully handle empty collections.
        return view('exports.pointage', [
            'quinzaine' => (object)[
                'label' => 'Aucune Quinzaine',
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'enterprise' => $this->enterprise,
                'is_closed' => true, // Treat as closed for display purposes
            ],
            'employees' => collect(),
            'days' => [],
            'records' => collect(),
            'payrollService' => null, // No payroll service needed for empty data
            'operations' => collect(),
            'blocs' => collect(),
            'blocMatrices' => [],
            'isEmptySheet' => true, // Flag to indicate it's an empty sheet
        ]);
    }

    public function title(): string
    {
        return $this->enterprise->name;
    }
}
