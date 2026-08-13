<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\Vehicle;
use App\Models\Employee; // Import Employee model
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia; // Import Inertia

class VehicleController extends Controller
{
    /**
     * show/update/toggleActive/destroy trusted the route-bound $vehicle with no
     * ownership check — any authenticated user could view or mutate another farm's
     * vehicle by walking IDs.
     */
    private function assertVehicleInScope(Request $request, Vehicle $vehicle): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $vehicle->farm_id === $farmId, 403);
    }

    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $query = Vehicle::with('defaultDriver')
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId));

        if ($request->has('asset_type')) {
            $query->where('asset_type', $request->asset_type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $vehicles = $query->orderBy('name')->get();

        $types = $this->types()->original;
        $equipmentTypes = $this->equipmentTypes()->original;
        $fuelTypes = $this->fuelTypes()->original;
        $employees = Employee::where('is_active', true)
            ->when($farmId, fn ($q) => $q->whereHas('enterprise', fn ($eq) => $eq->where('farm_id', $farmId)))
            ->get(['id', 'full_name']);

        return Inertia::render('Stock/Vehicles/Index', [
            'vehicles' => $vehicles,
            'types' => $types,
            'equipmentTypes' => $equipmentTypes,
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
            'quad',
            'other'
        ];

        return response()->json($types);
    }

    // Non-vehicle assets (pumps, generators, sprayers, tools) — the "type" column is a plain
    // string now (see migration), so this list is validated in the controller, not the DB.
    public function equipmentTypes(): JsonResponse
    {
        $types = [
            'pump',
            'generator',
            'sprayer',
            'compressor',
            'mulcher',
            'plow',
            'mower',
            'leveler',
            'roller',
            'tool',
            'other',
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

    // Equipment (pumps, generators...) has no plate — only vehicles do.
    private function allowedTypesFor(string $assetType): array
    {
        return $assetType === 'equipment' ? $this->equipmentTypes()->original : $this->types()->original;
    }

    public function store(Request $request)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }

        $validated = $request->validate([
            'asset_type' => 'required|in:vehicle,equipment',
            'name' => 'required|string|max:255',
            'plate_number' => 'required_if:asset_type,vehicle|nullable|string|unique:vehicles,plate_number',
            'serial_number' => 'nullable|string|max:255',
            'type' => ['required', Rule::in($this->allowedTypesFor($request->input('asset_type')))],
            'model' => 'nullable|string|max:255',
            'fuel_type' => 'nullable|string|max:50',
            'capacity_liters' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:operational,in_repair,retired',
            'quantity' => 'nullable|integer|min:1',
            'default_driver_id' => 'nullable|exists:employees,id',
            'is_active' => 'boolean',
            'is_location' => 'boolean',
            'default_daily_rate' => 'nullable|numeric|min:0',
            'purchase_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        Vehicle::create([
            'farm_id' => $this->resolveWriteFarmId($request),
            'asset_type' => $validated['asset_type'],
            'name' => $validated['name'],
            'plate_number' => $validated['plate_number'] ?? null,
            'serial_number' => $validated['serial_number'] ?? null,
            'type' => $validated['type'],
            'model' => $validated['model'] ?? null,
            'fuel_type' => $validated['fuel_type'] ?? 'diesel',
            'capacity_liters' => $validated['capacity_liters'] ?? null,
            'status' => $validated['status'] ?? 'operational',
            'quantity' => $validated['quantity'] ?? 1,
            'default_driver_id' => $validated['default_driver_id'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'is_location' => $validated['is_location'] ?? false,
            'default_daily_rate' => $validated['default_daily_rate'] ?? null,
            'purchase_date' => $validated['purchase_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Véhicule créé avec succès.');
    }

    public function show(Request $request, Vehicle $vehicle)
    {
        $this->assertVehicleInScope($request, $vehicle);

        $vehicle->load([
            'defaultDriver',
            'fuelTransactions.product',
            'fuelTransactions.driver',
            'manualStockEntries.product',
            'manualStockEntries.employee',
            'manualStockEntries.stockMovement',
            'usages' => fn ($q) => $q->orderByDesc('date'),
            'maintenanceLogs' => fn ($q) => $q->orderByDesc('performed_at')
                ->with(['performedBy', 'manualStockEntries.product', 'manualStockEntries.stockMovement']),
        ]);

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
            'equipmentTypes' => $this->equipmentTypes()->original,
            'fuelTypes' => $this->fuelTypes()->original,
            'employees' => $employees,
        ]);
    }

    public function toggleActive(Request $request, Vehicle $vehicle)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }
        $this->assertVehicleInScope($request, $vehicle);

        $vehicle->update(['is_active' => !$vehicle->is_active]);

        return redirect()->back()->with('success', $vehicle->is_active ? 'Véhicule activé.' : 'Véhicule désactivé.');
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        // data_entry is intentionally allowed to update (see test_data_entry_can_update_vehicle).
        $this->assertVehicleInScope($request, $vehicle);

        $effectiveAssetType = $request->input('asset_type', $vehicle->asset_type);

        $validated = $request->validate([
            'asset_type' => 'sometimes|required|in:vehicle,equipment',
            'name' => 'sometimes|required|string|max:255',
            'plate_number' => 'required_if:asset_type,vehicle|nullable|string|unique:vehicles,plate_number,' . $vehicle->id,
            'serial_number' => 'nullable|string|max:255',
            'type' => ['sometimes', 'required', Rule::in($this->allowedTypesFor($effectiveAssetType))],
            'model' => 'nullable|string|max:255',
            'fuel_type' => 'nullable|string|max:50',
            'capacity_liters' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:operational,in_repair,retired',
            'quantity' => 'nullable|integer|min:1',
            'default_driver_id' => 'nullable|exists:employees,id',
            'is_active' => 'boolean',
            'is_location' => 'boolean',
            'default_daily_rate' => 'nullable|numeric|min:0',
            'purchase_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        // asset_type=equipment clears plate_number instead of leaving a stale plate on
        // an item that switched from vehicle to equipment.
        if (($validated['asset_type'] ?? $effectiveAssetType) === 'equipment' && !array_key_exists('plate_number', $validated)) {
            $validated['plate_number'] = null;
        }

        $vehicle->update($validated);

        return redirect()->back()->with('success', 'Véhicule mis à jour avec succès.');
    }

    public function destroy(Request $request, Vehicle $vehicle)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }
        $this->assertVehicleInScope($request, $vehicle);

        // Vehicle has no soft-deletes, and fuel_transactions/manual_stock_entries reference it
        // with no cascade — the DB would already reject this delete via a foreign key
        // violation, but check up front so the magasinier gets an actionable message instead
        // of a crash. maintenance_logs DOES cascade-delete, so it's checked here explicitly
        // to avoid silently wiping repair history along with the asset.
        if ($vehicle->fuelTransactions()->exists() || $vehicle->manualStockEntries()->exists() || $vehicle->maintenanceLogs()->exists()) {
            return redirect()->back()->withErrors([
                'vehicle' => 'Ce véhicule a un historique (carburant ou sorties de stock) et ne peut pas être supprimé. Désactivez-le plutôt depuis "Modifier".',
            ]);
        }

        $vehicle->delete();

        return redirect()->route('stock.vehicles.index')->with('success', 'Véhicule supprimé avec succès.');
    }
}
