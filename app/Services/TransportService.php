<?php

namespace App\Services;

use App\Models\Quinzaine;
use App\Models\TransportSnapshot;
use App\Models\TransportVehicleAttendance;
use Carbon\Carbon;

class TransportService
{
    /**
     * A vehicle's operating-day is a farm-wide fact (every division/enterprise of a farm
     * shares the same pooled vehicles), not a per-quinzaine one — so this groups
     * TransportVehicleAttendance rows by date range + farm, never by quinzaine_id. Shared by
     * generateSnapshot() (freezing) and TransportController::summary() (live preview) so both
     * read the exact same, single, non-duplicated picture of a period's cost.
     */
    public function previewForDateRange(Carbon $start, Carbon $end, int $farmId): array
    {
        $attendances = TransportVehicleAttendance::whereBetween('date', [$start, $end])
            ->whereHas('transportVehicle', fn ($q) => $q->where('farm_id', $farmId))
            ->with(['transportVehicle.transportCompany', 'transportVehicle.employees.residenceLocation'])
            ->get();

        $groups = $attendances->groupBy('transport_vehicle_id');

        return $groups->map(function ($group) {
            $vehicle = $group->first()->transportVehicle;
            if (!$vehicle) {
                return null;
            }

            $daysCount = $group->count();
            $netPerDay = $vehicle->netPerDay();

            return [
                'transport_vehicle_id' => $vehicle->id,
                'transport_company_id' => $vehicle->transport_company_id,
                'vehicle_code' => $vehicle->code,
                'company_name' => $vehicle->transportCompany?->name,
                'net_per_day' => $netPerDay,
                'days_count' => $daysCount,
                'subtotal' => $netPerDay * $daysCount,
                'employee_count' => $vehicle->employees->count(),
            ];
        })->filter()->values()->all();
    }

    /**
     * Freezes this period's transport cost into transport_snapshots — one row per vehicle that
     * operated at least one day. "This period" means every sibling Quinzaine sharing the exact
     * same start/end dates for this farm (see the class doc above for why), not just the one
     * quinzaine being closed: several divisions can close independently, in any order, so
     * existing snapshot rows for ANY sibling are cleared first and fresh ones written anchored
     * to whichever quinzaine is closing now. Idempotent — safe to call from every division's
     * close, always leaves exactly one snapshot set per period, never duplicated.
     */
    public function generateSnapshot(Quinzaine $quinzaine): void
    {
        $quinzaine->loadMissing('enterprise');
        $farmId = $quinzaine->enterprise->farm_id;

        $siblingIds = Quinzaine::where('start_date', $quinzaine->start_date)
            ->where('end_date', $quinzaine->end_date)
            ->whereHas('enterprise', fn ($q) => $q->where('farm_id', $farmId))
            ->pluck('id');

        TransportSnapshot::whereIn('quinzaine_id', $siblingIds)->delete();

        $rows = $this->previewForDateRange($quinzaine->start_date, $quinzaine->end_date, $farmId);

        foreach ($rows as $row) {
            TransportSnapshot::create([
                'quinzaine_id' => $quinzaine->id,
                'transport_vehicle_id' => $row['transport_vehicle_id'],
                'transport_company_id' => $row['transport_company_id'],
                'vehicle_code' => $row['vehicle_code'],
                'company_name' => $row['company_name'],
                'net_per_day' => $row['net_per_day'],
                'days_count' => $row['days_count'],
                'subtotal' => $row['subtotal'],
                'employee_count' => $row['employee_count'],
            ]);
        }
    }
}
