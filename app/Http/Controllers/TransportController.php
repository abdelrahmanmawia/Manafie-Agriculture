<?php

namespace App\Http\Controllers;

use App\Models\Quinzaine;
use App\Models\TransportSnapshot;
use App\Models\TransportVehicle;
use App\Models\TransportVehicleAttendance;
use App\Services\TransportService;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TransportController extends Controller
{
    /**
     * Same three-branch check as PointageController's private assertQuinzaineInScope() — kept
     * as its own copy here rather than shared, matching how each controller in this codebase
     * already owns its own scope-assertion methods rather than reaching into another one.
     */
    private function assertQuinzaineInScope($user, Quinzaine $quinzaine): void
    {
        if ($user->enterprise_id) {
            abort_unless($quinzaine->enterprise_id === $user->enterprise_id, 403);
        } elseif ($user->farm_id) {
            abort_unless($quinzaine->enterprise->farm_id === $user->farm_id, 403);
        } elseif ($user->role === 'super_admin') {
            abort_unless($quinzaine->enterprise->farm_id === (int) session('active_farm_id'), 403);
        } else {
            abort(403);
        }
    }

    /**
     * Day-by-day grid, rows = vehicles (farm-pooled, not enterprise-scoped — same reasoning
     * Transport/Vehicles.jsx already uses), columns = each day of this quinzaine. Marking a
     * cell records that the vehicle operated that day (TransportVehicleAttendance); a vehicle's
     * net/day (auto or fixed, see TransportVehicle::netPerDay()) times marked days is its cost
     * for this quinzaine, mirroring how PointageController::grid() drives payroll.
     */
    public function grid(Request $request, $quinzaineId)
    {
        $user = $request->user();
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        $this->assertQuinzaineInScope($user, $quinzaine);

        $farmId = $quinzaine->enterprise->farm_id;

        $vehicles = TransportVehicle::with(['transportCompany', 'employees.residenceLocation'])
            ->where('farm_id', $farmId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $period = CarbonPeriod::create($quinzaine->start_date, $quinzaine->end_date);
        $days = [];
        foreach ($period as $date) {
            $days[] = $date->format('Y-m-d');
        }

        // Farm-wide, not this quinzaine's own — a sibling division's Quinzaine for the same
        // real-world period shares these exact rows (see TransportService's class doc), so
        // marking a cell here is immediately visible from any sibling division's grid too.
        $existingAttendances = TransportVehicleAttendance::whereBetween('date', [$quinzaine->start_date, $quinzaine->end_date])
            ->whereHas('transportVehicle', fn ($q) => $q->where('farm_id', $farmId))
            ->get()
            ->groupBy(['transport_vehicle_id', function ($item) {
                return $item->date->format('Y-m-d');
            }]);

        return Inertia::render('Transport/Grid', [
            'quinzaine' => $quinzaine,
            'vehicles' => $vehicles->map(fn ($v) => [
                'id' => $v->id,
                'code' => $v->code,
                'company_name' => $v->transportCompany?->name,
                'fixed_net_per_day' => $v->fixed_net_per_day,
                'net_per_day' => $v->netPerDay(),
                'employee_count' => $v->employees->count(),
            ]),
            'days' => $days,
            'existingAttendances' => $existingAttendances,
        ]);
    }

    public function toggleAttendance(Request $request)
    {
        $validated = $request->validate([
            'transport_vehicle_id' => 'required|exists:transport_vehicles,id',
            'quinzaine_id' => 'required|exists:quinzaines,id',
            'date' => 'required|date',
        ]);

        $quinzaine = Quinzaine::with('enterprise')->findOrFail($validated['quinzaine_id']);
        $this->assertQuinzaineInScope($request->user(), $quinzaine);

        // Same reasoning as PointageController::updateCell(): exists:transport_vehicles,id only
        // proves the ID exists SOMEWHERE, not that it belongs to this farm.
        $vehicle = TransportVehicle::findOrFail($validated['transport_vehicle_id']);
        abort_unless($vehicle->farm_id === $quinzaine->enterprise->farm_id, 403);

        // Checked against whichever division's grid the request came from — a simple, reasonable
        // proxy; this doesn't try to reason about a sibling division's own close state, since
        // the attendance fact itself is shared farm-wide regardless (see TransportService).
        if ($quinzaine->is_closed) {
            return redirect()->back()->withErrors(['date' => 'Cette période est clôturée et ne peut plus être modifiée.']);
        }

        $existing = TransportVehicleAttendance::where('transport_vehicle_id', $validated['transport_vehicle_id'])
            ->whereDate('date', $validated['date']);

        if ($existing->exists()) {
            $existing->delete();
        } else {
            TransportVehicleAttendance::create([
                'transport_vehicle_id' => $validated['transport_vehicle_id'],
                'date' => $validated['date'],
            ]);
        }

        return redirect()->back();
    }

    // Transport is pooled at the farm level (a company/vehicle can carry employees from any of
    // the farm's enterprises together), unlike Payroll's per-enterprise view — so periods here
    // are grouped across every enterprise of the active farm, not filtered to one.
    public function summary(Request $request, TransportService $transportService)
    {
        $farmId = $this->scopedFarmId($request);

        $quinzaines = Quinzaine::query()
            ->when($farmId, fn ($q) => $q->whereHas('enterprise', fn ($eq) => $eq->where('farm_id', $farmId)))
            ->orderBy('start_date', 'desc')
            ->get();

        $frenchMonths = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];
        $periods = $quinzaines
            ->groupBy(fn ($q) => $q->start_date->format('Y-m-d') . '_' . $q->end_date->format('Y-m-d'))
            ->map(function ($group) use ($frenchMonths) {
                $first = $group->first();
                $qzNum = ((int) $first->start_date->format('j')) <= 15 ? '1' : '2';
                return [
                    'key' => $first->start_date->format('Y-m-d') . '_' . $first->end_date->format('Y-m-d'),
                    'label' => $qzNum . 'QZ ' . $frenchMonths[(int) $first->start_date->format('n')] . ' ' . $first->start_date->format('Y'),
                    'quinzaine_ids' => $group->pluck('id')->all(),
                    'is_closed' => $group->every(fn ($q) => $q->is_closed),
                    'start_date' => $first->start_date,
                    'end_date' => $first->end_date,
                ];
            })
            ->sortByDesc(fn ($p) => $p['key'])
            ->values();

        $selectedKey = $request->query('period') ?: ($periods->first()['key'] ?? null);
        $selectedPeriod = $periods->firstWhere('key', $selectedKey);

        $breakdown = [];
        if ($selectedPeriod) {
            if ($selectedPeriod['is_closed']) {
                // generateSnapshot() guarantees only ONE sibling quinzaine_id actually holds
                // rows for this period at a time (whichever closed most recently) — querying
                // across every sibling id here just finds that one set, nothing to merge.
                $rows = TransportSnapshot::whereIn('quinzaine_id', $selectedPeriod['quinzaine_ids'])->get()->map(fn ($s) => $s->only([
                    'company_name', 'vehicle_code', 'net_per_day', 'days_count', 'subtotal', 'employee_count',
                ]))->all();
            } else {
                // Live preview reads the whole period in one call — attendance is farm-wide, not
                // per-quinzaine, so every sibling division already shares the same picture; no
                // merging across quinzaine_ids needed (unlike before this was fixed).
                $rows = $transportService->previewForDateRange($selectedPeriod['start_date'], $selectedPeriod['end_date'], $farmId);
            }

            $breakdown = collect($rows)
                ->sortBy(fn ($row) => $row['company_name'] . $row['vehicle_code'])
                ->values()
                ->all();
        }

        return Inertia::render('Transport/Summary', [
            // A representative quinzaine_id per period — any sibling works equally (attendance
            // is shared farm-wide, see TransportService), used to link to the Grille Transport
            // for that period without picking a specific division.
            'periods' => $periods->map(fn ($p) => ['key' => $p['key'], 'label' => $p['label'], 'is_closed' => $p['is_closed'], 'quinzaine_id' => $p['quinzaine_ids'][0]])->values(),
            'selectedPeriodKey' => $selectedKey,
            'breakdown' => $breakdown,
            'isClosed' => $selectedPeriod['is_closed'] ?? false,
        ]);
    }
}
