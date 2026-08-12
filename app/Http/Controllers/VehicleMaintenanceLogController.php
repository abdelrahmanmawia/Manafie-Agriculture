<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleMaintenanceLog;
use Illuminate\Http\Request;

class VehicleMaintenanceLogController extends Controller
{
    private function assertVehicleInScope(Request $request, Vehicle $vehicle): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $vehicle->farm_id === $farmId, 403);
    }

    private function assertLogInScope(Request $request, VehicleMaintenanceLog $log): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $log->farm_id === $farmId, 403);
    }

    public function store(Request $request, Vehicle $vehicle)
    {
        // Matches ManualStockEntryController::store() / VehicleController::store() — data_entry
        // may log day-to-day operations (updates, sorties) but not create new structural
        // records. A repair log is closer to the latter than to a simple stock movement.
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }
        $this->assertVehicleInScope($request, $vehicle);

        $validated = $request->validate([
            'description' => 'required|string',
            'performed_at' => 'required|date',
            'performed_by_id' => 'nullable|exists:employees,id',
            'next_due_date' => 'nullable|date',
        ]);

        $vehicle->maintenanceLogs()->create([
            ...$validated,
            'farm_id' => $vehicle->farm_id,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Intervention de maintenance enregistrée.');
    }

    public function update(Request $request, VehicleMaintenanceLog $maintenanceLog)
    {
        $this->assertLogInScope($request, $maintenanceLog);

        $validated = $request->validate([
            'description' => 'sometimes|required|string',
            'performed_at' => 'sometimes|required|date',
            'performed_by_id' => 'nullable|exists:employees,id',
            'next_due_date' => 'nullable|date',
        ]);

        $maintenanceLog->update($validated);

        return redirect()->back()->with('success', 'Intervention de maintenance mise à jour.');
    }

    public function destroy(Request $request, VehicleMaintenanceLog $maintenanceLog)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }
        $this->assertLogInScope($request, $maintenanceLog);

        $maintenanceLog->delete();

        return redirect()->back()->with('success', 'Intervention de maintenance supprimée.');
    }
}
