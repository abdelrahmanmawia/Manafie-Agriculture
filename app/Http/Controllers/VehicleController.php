<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\Vehicle;
use App\Models\Employee; // Import Employee model
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia; // Import Inertia

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $query = Vehicle::with('defaultDriver')
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId));

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $vehicles = $query->orderBy('name')->get();

        $types = $this->types()->original;
        $fuelTypes = $this->fuelTypes()->original;
        $employees = Employee::where('is_active', true)
            ->when($farmId, fn ($q) => $q->whereHas('enterprise', fn ($eq) => $eq->where('farm_id', $farmId)))
            ->get(['id', 'full_name']);

        return Inertia::render('Stock/Vehicles/Index', [
            'vehicles' => $vehicles,
            'types' => $types,
            'fuelTypes' => $fuelTypes,
            'employees' => $employees,        ]);
    }

    public function types(): JsonResponse
    {
        $types = [
            'tractor',
            'truck',
            'van',
            'car',
            'other'
        ];

        return response()->json($types);
    }

    public function fuelTypes(): JsonResponse
    {
        $fuelTypes = [
            'diesel',
            'gasoline',
            'electric',
            'other'
        ];

        return response()->json($fuelTypes);
    }

    public function store(Request $request)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'plate_number' => 'required|string|unique:vehicles,plate_number',
            'type' => 'required|in:tractor,truck,van,car,other',
            'model' => 'nullable|string|max:255',
            'fuel_type' => 'nullable|string|max:50',
            'default_driver_id' => 'nullable|exists:employees,id',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        Vehicle::create([
            'farm_id' => $this->resolveWriteFarmId($request),
            'name' => $validated['name'],
            'plate_number' => $validated['plate_number'],
            'type' => $validated['type'],
            'model' => $validated['model'] ?? null,
            'fuel_type' => $validated['fuel_type'] ?? 'diesel',
            'default_driver_id' => $validated['default_driver_id'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->back();
    }

    public function show(Vehicle $vehicle)
    {
        $vehicle->load(
            'defaultDriver',
            'fuelTransactions.product',
            'fuelTransactions.driver',
            'manualStockEntries.product',
            'manualStockEntries.employee',
            'manualStockEntries.stockMovement'
        );

        // Keep the vehicle's currently assigned driver selectable even if they've since gone
        // inactive, so editing the vehicle doesn't silently drop that field.
        $employees = Employee::whereHas('enterprise', fn ($q) => $q->where('farm_id', $vehicle->farm_id))
            ->where(function ($q) use ($vehicle) {
                $q->where('is_active', true);
                if ($vehicle->default_driver_id) {
                    $q->orWhere('id', $vehicle->default_driver_id);
                }
            })
            ->get(['id', 'full_name']);

        return Inertia::render('Stock/Vehicles/Show', [
            'vehicle' => $vehicle,
            'types' => $this->types()->original,
            'fuelTypes' => $this->fuelTypes()->original,
            'employees' => $employees,
        ]);
    }

    public function toggleActive(Request $request, Vehicle $vehicle)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }

        $vehicle->update(['is_active' => !$vehicle->is_active]);

        return redirect()->back();
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'plate_number' => 'sometimes|required|string|unique:vehicles,plate_number,' . $vehicle->id,
            'type' => 'sometimes|required|in:tractor,truck,van,car,other',
            'model' => 'nullable|string|max:255',
            'fuel_type' => 'nullable|string|max:50',
            'default_driver_id' => 'nullable|exists:employees,id',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $vehicle->update($validated);

        return redirect()->back();
    }

    public function destroy(Request $request, Vehicle $vehicle)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }

        // Vehicle has no soft-deletes, and fuel_transactions/manual_stock_entries reference it
        // with no cascade — the DB would already reject this delete via a foreign key
        // violation, but check up front so the magasinier gets an actionable message instead
        // of a crash.
        if ($vehicle->fuelTransactions()->exists() || $vehicle->manualStockEntries()->exists()) {
            return redirect()->back()->withErrors([
                'vehicle' => 'Ce véhicule a un historique (carburant ou sorties de stock) et ne peut pas être supprimé. Désactivez-le plutôt depuis "Modifier".',
            ]);
        }

        $vehicle->delete();

        return redirect()->route('stock.vehicles.index');
    }
}
