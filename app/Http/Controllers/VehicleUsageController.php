<?php

namespace App\Http\Controllers;

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

        return Inertia::render('Stock/VehicleUsage/Index', [
            'vehicles' => $vehicles,
            'days' => $days,
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'existingUsages' => $usages,
        ]);
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
            'farm_id' => $this->resolveWriteFarmId($request),
            'date' => $date,
            'daily_rate' => $vehicle->default_daily_rate,
        ]);

        return redirect()->back();
    }
}
