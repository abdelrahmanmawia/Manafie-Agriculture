<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\FuelTransaction;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Read-only history of past fill-ups. New fuel/vehicle consumption is now logged through
 * ManualStockEntryController (Entrée Manuelle) so there is a single place for every sortie —
 * see ManualStockEntries/Index.jsx, which shows extra fields (odomètre, heures, prix) once a
 * vehicle is selected.
 */
class FuelTransactionController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $query = FuelTransaction::with('vehicle', 'product', 'driver', 'performedBy')
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId));

        if ($request->has('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }

        if ($request->has('driver_id')) {
            $query->where('driver_id', $request->driver_id);
        }

        if ($request->has('from_date')) {
            $query->where('date', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('date', '<=', $request->to_date);
        }

        $fuelTransactions = $query->orderBy('date', 'desc')->get();

        $vehicles = Vehicle::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name', 'plate_number']);

        return Inertia::render('Stock/FuelTransactions/Index', [
            'fuelTransactions' => $fuelTransactions,
            'vehicles' => $vehicles,
        ]);
    }

    public function show(FuelTransaction $transaction)
    {
        $transaction->load('vehicle', 'product', 'driver', 'performedBy');

        return Inertia::render('Stock/FuelTransactions/Show', [
            'fuelTransaction' => $transaction,
        ]);
    }
}
