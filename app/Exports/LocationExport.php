<?php

namespace App\Exports;

use App\Models\Farm;
use App\Models\Vehicle;
use App\Models\VehicleUsage;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;
use Carbon\CarbonPeriod;

/**
 * Bridges Stock's vehicle-usage tracking into the Pointage payroll export, at export time only —
 * Vehicle/VehicleUsage stay farm-scoped (no Quinzaine/Enterprise FK, matching the rest of Stock);
 * this sheet just matches that farm's usage rows to the exported quinzaine's own date range.
 */
class LocationExport implements FromView, ShouldAutoSize, WithTitle
{
    protected Farm $farm;
    protected $startDate;
    protected $endDate;

    public function __construct(Farm $farm, $startDate, $endDate)
    {
        $this->farm = $farm;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function view(): View
    {
        $period = CarbonPeriod::create($this->startDate, $this->endDate);
        $days = [];
        foreach ($period as $date) {
            $days[] = $date->format('Y-m-d');
        }

        $vehicles = Vehicle::where('farm_id', $this->farm->id)->orderBy('name')->get();

        $usages = VehicleUsage::where('farm_id', $this->farm->id)
            ->whereBetween('date', [$this->startDate->format('Y-m-d'), $this->endDate->format('Y-m-d')])
            ->get()
            ->groupBy(['vehicle_id', fn ($item) => $item->date->format('Y-m-d')]);

        return view('exports.location', [
            'farm' => $this->farm,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'days' => $days,
            'vehicles' => $vehicles,
            'usages' => $usages,
        ]);
    }

    public function title(): string
    {
        return 'LOCATION';
    }
}
