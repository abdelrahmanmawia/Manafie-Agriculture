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
        $employees = Employee::when($farmId, fn ($q) => $q->whereHas('enterprise', fn ($eq) => $eq->where('farm_id', $farmId)))->get(['id', 'full_name']);

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

        return Inertia::render('Stock/Vehicles/Show', [
            'vehicle' => $vehicle,
        ]);
    }

    public function edit(Vehicle $vehicle)
    {
        $types = $this->types()->original;
        $fuelTypes = $this->fuelTypes()->original;
        $employees = Employee::whereHas('enterprise', fn ($q) => $q->where('farm_id', $vehicle->farm_id))->get(['id', 'full_name']);

        return Inertia::render('Stock/Vehicles/Edit', [
            'vehicle' => $vehicle,
            'types' => $types,
            'fuelTypes' => $fuelTypes,
            'employees' => $employees,
        ]);
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

        return redirect()->route('stock.vehicles.show', $vehicle);
    }

    public function destroy(Vehicle $vehicle)
    {
        $vehicle->delete();

        return redirect()->route('stock.vehicles.index');
    }
}
