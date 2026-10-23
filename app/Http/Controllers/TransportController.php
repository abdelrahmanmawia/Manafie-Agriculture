<?php

namespace App\Http\Controllers;

use App\Models\Quinzaine;
use App\Models\TransportSnapshot;
use App\Models\TransportVehicle;
use App\Models\TransportVehicleAbsence;
use App\Services\TransportService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TransportController extends Controller
{
    /**
     * Day-by-day grid, rows = vehicles (farm-pooled, not enterprise-scoped), columns = each day of
     * this quinzaine. Each cell shows that day's riders and cost, computed from the pointage
     * (TransportService::daily()); the only thing set here by hand is "the vehicle didn't come"
     * (setAbsence()).
     */
    public function grid(Request $request, TransportService $transportService, $quinzaineId)
    {
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        $this->assertQuinzaineInScope($request, $quinzaine);

        $period = CarbonPeriod::create($quinzaine->start_date, $quinzaine->end_date);
        $days = [];
        foreach ($period as $date) {
            $days[] = $date->format('Y-m-d');
        }

        $daily = $transportService->daily($quinzaine->start_date, $quinzaine->end_date, $quinzaine->enterprise->farm_id);

        return Inertia::render('Transport/Grid', [
            'quinzaine' => $quinzaine,
            'vehicles' => $daily['vehicles']->values()->map(fn ($v) => [
                'id' => $v->id,
                'code' => $v->code,
                'company_name' => $v->transportCompany?->name,
                'price_per_person' => $v->price_per_person,
                'fixed_net_per_day' => $v->fixed_net_per_day,
            ]),
            'days' => $days,
            'cells' => (object) $daily['days'],
        ]);
    }

    /**
     * Marks one vehicle as not having come (absent=true) on one day, or clears that (false): its
     * riders who worked that day came on their own, so they're paid but the vehicle costs
     * nothing that day. Takes the wanted state rather than toggling, so a double-click or a
     * retried request can't flip it back; setting a state that's already there is a no-op.
     */
    public function setAbsence(Request $request)
    {
        $validated = $request->validate([
            'transport_vehicle_id' => 'required|exists:transport_vehicles,id',
            'quinzaine_id' => 'required|exists:quinzaines,id',
            'date' => 'required|date',
            'absent' => 'required|boolean',
        ]);

        $quinzaine = Quinzaine::with('enterprise')->findOrFail($validated['quinzaine_id']);
        $this->assertQuinzaineInScope($request, $quinzaine);

        // exists:transport_vehicles,id only proves the ID exists SOMEWHERE, not on this farm.
        $vehicle = TransportVehicle::findOrFail($validated['transport_vehicle_id']);
        abort_unless($vehicle->farm_id === $quinzaine->enterprise->farm_id, 403);

        // Checked against whichever division's grid the request came from — the absence itself
        // is a farm-wide fact shared by every sibling division (see TransportService).
        if ($quinzaine->is_closed) {
            return redirect()->back()->withErrors(['date' => 'Cette période est clôturée et ne peut plus être modifiée.']);
        }

        // The closed check above only means anything for a date inside this quinzaine —
        // otherwise any open quinzaine id could be used to change a day of a closed period.
        $day = Carbon::parse($validated['date'])->startOfDay();
        if (! $day->betweenIncluded($quinzaine->start_date->copy()->startOfDay(), $quinzaine->end_date->copy()->startOfDay())) {
            return redirect()->back()->withErrors(['date' => 'Cette date est en dehors de la période de la quinzaine.']);
        }

        $existing = TransportVehicleAbsence::where('transport_vehicle_id', $vehicle->id)
            ->whereDate('date', $day->toDateString());

        if (! $request->boolean('absent')) {
            $existing->delete();
        } elseif (! $existing->exists()) {
            try {
                TransportVehicleAbsence::create(['transport_vehicle_id' => $vehicle->id, 'date' => $day->toDateString()]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // A concurrent request marked the same day first — already in the wanted state.
            }
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
                    'company_name', 'vehicle_code', 'price_per_person', 'net_per_day', 'days_count', 'rider_days', 'subtotal', 'employee_count',
                ]))->all();
            } else {
                // Live preview reads the whole period in one call — transport is farm-wide, not
                // per-quinzaine, so every sibling division already shares the same picture.
                $rows = $transportService->previewForDateRange($selectedPeriod['start_date'], $selectedPeriod['end_date'], $farmId);
            }

            $breakdown = collect($rows)
                ->sortBy(fn ($row) => $row['company_name'] . $row['vehicle_code'])
                ->values()
                ->all();
        }

        return Inertia::render('Transport/Summary', [
            // A representative quinzaine_id per period — any sibling works equally (transport
            // is shared farm-wide, see TransportService), used to link to the Grille Transport
            // for that period without picking a specific division.
            'periods' => $periods->map(fn ($p) => ['key' => $p['key'], 'label' => $p['label'], 'is_closed' => $p['is_closed'], 'quinzaine_id' => $p['quinzaine_ids'][0]])->values(),
            'selectedPeriodKey' => $selectedKey,
            'breakdown' => $breakdown,
            'isClosed' => $selectedPeriod['is_closed'] ?? false,
        ]);
    }
}
