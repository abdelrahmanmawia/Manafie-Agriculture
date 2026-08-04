<?php

namespace App\Http\Controllers;

use App\Models\Quinzaine;
use App\Models\Vehicle;
use App\Models\VehicleUsage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class VehicleUsageController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        if ($request->query('start_date') && $request->query('end_date')) {
            $startDate = Carbon::parse($request->query('start_date'));
            $endDate = Carbon::parse($request->query('end_date'));
        } else {
            // Default to the current half-month (same 1-15 / 16-end split Quinzaine periods use).
            $startDate = now()->day <= 15 ? now()->startOfMonth() : now()->startOfMonth()->addDays(15);
            $endDate = now()->day <= 15 ? now()->startOfMonth()->addDays(14) : now()->copy()->endOfMonth();
        }

        // Only vehicles explicitly flagged for location tracking show up here — most vehicles
        // are just used directly by the farm and have no place in a rental log.
        $vehicles = Vehicle::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('is_location', true)
            ->orderBy('name')
            ->get();

        $usages = VehicleUsage::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get()
            ->groupBy(['vehicle_id', fn ($item) => $item->date->format('Y-m-d')]);

        $days = [];
        foreach (CarbonPeriod::create($startDate, $endDate) as $date) {
            $days[] = $date->format('Y-m-d');
        }

        // Location has no Quinzaine of its own (it's farm-pooled, not enterprise-scoped), but a
        // day still counts as "closed" if ANY of this farm's enterprises has a closed Quinzaine
        // covering it — mirrors the Pointage grid's own is_closed guard, applied at the day level
        // since Location spans all of a farm's divisions at once.
        $closedRanges = $this->closedQuinzaineRanges($farmId, $startDate, $endDate);
        $closedDays = [];
        foreach ($days as $day) {
            foreach ($closedRanges as [$rangeStart, $rangeEnd]) {
                if ($day >= $rangeStart && $day <= $rangeEnd) {
                    $closedDays[] = $day;
                    break;
                }
            }
        }

        // Lets the user jump straight to a known period instead of only stepping prev/next.
        // Dedup by date range: the same real period usually has one Quinzaine per enterprise,
        // but Location is farm-wide and only cares about the date range once.
        $availableQuinzaines = Quinzaine::whereHas('enterprise', fn ($q) => $q->where('farm_id', $farmId))
            ->orderByDesc('start_date')
            ->get(['label', 'start_date', 'end_date'])
            ->unique(fn ($q) => $q->start_date->format('Y-m-d') . '_' . $q->end_date->format('Y-m-d'))
            ->map(fn ($q) => [
                'label' => $q->label,
                'start_date' => $q->start_date->format('Y-m-d'),
                'end_date' => $q->end_date->format('Y-m-d'),
            ])
            ->values();

        return Inertia::render('Stock/VehicleUsage/Index', [
            'vehicles' => $vehicles,
            'days' => $days,
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'existingUsages' => $usages,
            'closedDays' => $closedDays,
            'availableQuinzaines' => $availableQuinzaines,
        ]);
    }

    /**
     * @return array<int, array{0: string, 1: string}> start/end date pairs of closed Quinzaines
     *                                                  overlapping the given window, for this farm.
     */
    private function closedQuinzaineRanges(?int $farmId, Carbon $startDate, Carbon $endDate): array
    {
        return Quinzaine::whereHas('enterprise', fn ($q) => $q->where('farm_id', $farmId))
            ->where('is_closed', true)
            ->where('start_date', '<=', $endDate->format('Y-m-d'))
            ->where('end_date', '>=', $startDate->format('Y-m-d'))
            ->get(['start_date', 'end_date'])
            ->map(fn ($q) => [$q->start_date->format('Y-m-d'), $q->end_date->format('Y-m-d')])
            ->all();
    }

    /**
     * One-click toggle, mirroring the Pointage grid's own quick-toggle interaction: a cell isn't
     * "used" or "not used" via a rate the user retypes every day — clicking it just flips
     * presence, using the vehicle's own default_daily_rate (set once, changeable any time from
     * the vehicle's edit form) as the rate for that day.
     */
    public function cell(Request $request)
    {
        $validated = $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'date' => 'required|date',
        ]);

        $date = Carbon::parse($validated['date'])->format('Y-m-d');
        $farmId = $this->resolveWriteFarmId($request);

        // Never trust the client's own greyed-out styling — re-check server-side that this date
        // doesn't fall inside a closed Quinzaine before writing anything, same guard
        // PointageController::updateCell applies for pointage itself.
        $isClosed = !empty($this->closedQuinzaineRanges($farmId, Carbon::parse($date), Carbon::parse($date)));
        if ($isClosed) {
            // Inertia's base middleware shares `errors` automatically (unlike a plain session
            // `with()` flash, which nothing in this app's frontend actually reads) — withErrors()
            // is the pattern already proven to reach the page, same as the daily_rate error below.
            return redirect()->back()->withErrors([
                'date' => 'Cette période est clôturée et ne peut plus être modifiée.',
            ]);
        }

        $existing = VehicleUsage::where('vehicle_id', $validated['vehicle_id'])
            ->whereDate('date', $date)
            ->first();

        if ($existing) {
            $existing->delete();

            return redirect()->back();
        }

        $vehicle = Vehicle::findOrFail($validated['vehicle_id']);
        if (!$vehicle->default_daily_rate) {
            return redirect()->back()->withErrors([
                'daily_rate' => 'Ce véhicule n\'a pas de tarif journalier par défaut. Renseignez-le depuis "Modifier" sur la page Véhicules.',
            ]);
        }

        VehicleUsage::create([
            'vehicle_id' => $validated['vehicle_id'],
            'farm_id' => $farmId,
            'date' => $date,
            'daily_rate' => $vehicle->default_daily_rate,
        ]);

        return redirect()->back();
    }
}
