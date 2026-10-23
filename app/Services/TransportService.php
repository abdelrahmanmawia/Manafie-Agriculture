<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\TransportSnapshot;
use App\Models\TransportVehicle;
use App\Models\TransportVehicleAbsence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Transport cost is computed from the pointage, never ticked by hand. On each day, a vehicle's
 * riders are the distinct workers who worked that day (a pointage record with an operation —
 * an unworked jour férié doesn't count, they didn't come) and whose record carries that vehicle
 * (stamped from their équipe when the record was written, see riderVehicleId()). Its cost that
 * day is riders × price_per_person, or its flat fixed_net_per_day when one is set, and 0 when
 * nobody rode it or when it's marked absent that day (TransportVehicleAbsence: the riders came on
 * their own — they are paid, the vehicle isn't).
 *
 * Vehicles are farm-pooled (every division's workers share them), so everything here is by date
 * range + farm, never by quinzaine: sibling divisions' quinzaines for the same dates all read the
 * same single picture.
 */
class TransportService
{
    /** The vehicle a worker rides today: their équipe's, if it rides one. */
    public function riderVehicleId(int $employeeId): ?int
    {
        return Employee::whereKey($employeeId)
            ->join('equipes', 'equipes.id', '=', 'employees.equipe_id')
            ->value('equipes.transport_vehicle_id');
    }

    public function dailyCost(TransportVehicle $vehicle, int $riders, bool $absent): float
    {
        if ($absent || $riders === 0) {
            return 0.0;
        }

        return $vehicle->fixed_net_per_day !== null
            ? (float) $vehicle->fixed_net_per_day
            : $riders * (float) $vehicle->price_per_person;
    }

    /**
     * Day by day, for the farm's vehicles: ['vehicles' => id-keyed TransportVehicle collection,
     * 'days' => [vehicle_id => [Y-m-d => ['riders' => int, 'absent' => bool, 'cost' => float]]]].
     * Only days with riders or an absence are listed. Lists the farm's active vehicles plus any
     * inactive one that still has riders or an absence in the range.
     */
    public function daily(Carbon $start, Carbon $end, int $farmId): array
    {
        $vehicles = TransportVehicle::with('transportCompany')->where('farm_id', $farmId)->orderBy('code')->get()->keyBy('id');
        $from = $start->toDateString();
        $to = $end->toDateString();

        $riders = PointageRecord::query()
            ->whereIn('transport_vehicle_id', $vehicles->keys())
            ->whereNotNull('operation_id')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->groupBy('transport_vehicle_id', DB::raw('DATE(date)'))
            ->selectRaw('transport_vehicle_id, DATE(date) as day, COUNT(DISTINCT employee_id) as riders')
            ->get();

        $absences = TransportVehicleAbsence::whereIn('transport_vehicle_id', $vehicles->keys())
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get();

        $days = [];
        foreach ($riders as $row) {
            $days[$row->transport_vehicle_id][Carbon::parse($row->day)->toDateString()] = ['riders' => (int) $row->riders, 'absent' => false];
        }
        foreach ($absences as $absence) {
            $day = $absence->date->toDateString();
            $days[$absence->transport_vehicle_id][$day] = ['riders' => $days[$absence->transport_vehicle_id][$day]['riders'] ?? 0, 'absent' => true];
        }
        foreach ($days as $vehicleId => $byDay) {
            foreach ($byDay as $day => $cell) {
                $days[$vehicleId][$day]['cost'] = $this->dailyCost($vehicles[$vehicleId], $cell['riders'], $cell['absent']);
            }
        }

        return [
            'vehicles' => $vehicles->filter(fn ($v) => $v->is_active || isset($days[$v->id])),
            'days' => $days,
        ];
    }

    /**
     * One row per vehicle that had riders in the range — the period's transport cost. Shared by
     * generateSnapshot() (freezing) and TransportController::summary() (live preview).
     */
    public function previewForDateRange(Carbon $start, Carbon $end, int $farmId): array
    {
        $daily = $this->daily($start, $end, $farmId);
        $memberCounts = Employee::join('equipes', 'equipes.id', '=', 'employees.equipe_id')
            ->whereIn('equipes.transport_vehicle_id', $daily['vehicles']->keys())
            ->groupBy('equipes.transport_vehicle_id')
            ->selectRaw('equipes.transport_vehicle_id, COUNT(*) as members')
            ->pluck('members', 'transport_vehicle_id');

        $rows = [];
        foreach ($daily['vehicles'] as $vehicle) {
            $charged = collect($daily['days'][$vehicle->id] ?? [])->filter(fn ($cell) => ! $cell['absent'] && $cell['riders'] > 0);
            if ($charged->isEmpty()) {
                continue;
            }

            $rows[] = [
                'transport_vehicle_id' => $vehicle->id,
                'transport_company_id' => $vehicle->transport_company_id,
                'vehicle_code' => $vehicle->code,
                'company_name' => $vehicle->transportCompany?->name,
                'price_per_person' => $vehicle->price_per_person,
                'net_per_day' => $vehicle->fixed_net_per_day,
                'days_count' => $charged->count(),
                'rider_days' => $charged->sum('riders'),
                'subtotal' => $charged->sum('cost'),
                'employee_count' => (int) ($memberCounts[$vehicle->id] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Freezes this period's transport cost into transport_snapshots — one row per vehicle that
     * had riders at least one day. "This period" means every sibling Quinzaine sharing the exact
     * same start/end dates for this farm, not just the one quinzaine being closed: several
     * divisions can close independently, in any order, so existing snapshot rows for ANY sibling
     * are cleared first and fresh ones written anchored to whichever quinzaine is closing now.
     * Idempotent — safe to call from every division's close; the last close freezes the final
     * picture, once every division's pointage is in.
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

        foreach ($this->previewForDateRange($quinzaine->start_date, $quinzaine->end_date, $farmId) as $row) {
            TransportSnapshot::create(['quinzaine_id' => $quinzaine->id] + $row);
        }
    }
}
