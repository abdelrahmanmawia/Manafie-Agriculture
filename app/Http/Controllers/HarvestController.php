<?php

namespace App\Http\Controllers;

use App\Models\Harvest;
use App\Models\Bloc;
use App\Models\Parcelle;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log; // Import Log facade
use Illuminate\Validation\ValidationException; // Import ValidationException

class HarvestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $farmId = $user->role === 'super_admin' ? session('active_farm_id') : $user->farm_id;

        if (!$farmId) {
            return Inertia::render('Admin/Harvests', [
                'harvests' => [],
                'blocs' => [],
                'varieties' => ['Hass', 'Fuerte', 'Lambhass', 'Zutano'],
                'grades' => ['Generale', 'Catégorie 1', 'Catégorie 2', 'Écart de tri'],
                'selectedFarmId' => null,
                'error' => 'Aucune ferme ne vous est assignée.'
            ]);
        }

        // Define grades with 'Generale' as an option
        $gradesOptions = ['Generale', 'Catégorie 1', 'Catégorie 2', 'Écart de tri'];

        $harvests = Harvest::with(['bloc', 'parcelle'])
            ->where('farm_id', $farmId)
            ->orderBy('date', 'desc')
            ->paginate(15)
            ->withQueryString();

        // Get all blocs (including main blocks and specific plots) sorted by name
        $blocs = Bloc::where('farm_id', $farmId)
            ->orderBy('name')
            ->get();

        // Get all parcelles for the farm
        $parcelles = \App\Models\Parcelle::whereHas('bloc', function($q) use ($farmId) {
            $q->where('farm_id', $farmId);
        })->with('sector')->get();

        // Get all sectors for the farm
        $sectors = \App\Models\Sector::whereHas('bloc', function($q) use ($farmId) {
            $q->where('farm_id', $farmId);
        })->orderBy('name')->get();

        return Inertia::render('Admin/Harvests', [
            'harvests' => $harvests,
            'blocs' => $blocs,
            'sectors' => $sectors,
            'parcelles' => $parcelles,
            'varieties' => ['Hass', 'Fuerte', 'Lambhass', 'Zutano'],
            'grades' => $gradesOptions,
            'selectedFarmId' => $farmId,
            'farm' => \App\Models\Farm::find($farmId),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $farmId = $user->role === 'super_admin' ? session('active_farm_id') : $user->farm_id;

        abort_unless($farmId, 403);

        $validated = $request->validate([
            'bloc_id' => 'required|exists:blocs,id',
            'sector_id' => 'nullable|exists:sectors,id',
            'parcelle_id' => 'nullable|exists:parcelles,id',
            'date' => 'required|date',
            'variety' => 'required|string',
            'boxes_count' => 'nullable|integer|min:0',
            'grade' => 'nullable|string',
            'unit_price_dh' => 'nullable|numeric|min:0',
            'comments' => 'nullable|string',
        ]);

        // Calculate estimated_kg based on boxes and farm's box weight
        $boxesCount = $validated['boxes_count'] ?? 0;
        $farm = \App\Models\Farm::find($farmId);
        $boxWeightKg = $farm ? $farm->box_weight_kg : 50; // Default to 50kg if not set
        $estimatedKg = $boxesCount * $boxWeightKg;

        // Use estimated_kg as quantity_kg for now (will be updated when weighed)
        $quantity = $estimatedKg > 0 ? $estimatedKg : ($validated['quantity_kg'] ?? 0);
        $price = $validated['unit_price_dh'] ?? 0;
        $totalRevenue = $quantity * $price;

        Harvest::create(array_merge($validated, [
            'farm_id' => $farmId,
            'quantity_kg' => $quantity,
            'estimated_kg' => $estimatedKg,
            'actual_kg' => null,
            'is_weighed' => false,
            'total_revenue_dh' => $totalRevenue
        ]));

        return redirect()->back()->with('success', 'Récolte enregistrée avec succès.');
    }

    public function bulkWeigh(Request $request)
    {
        Log::info('bulkWeigh method called.');
        Log::info('Raw request data:', $request->all()); // Log raw request data

        $user = $request->user();

        try {
            $validated = $request->validate([
                'harvest_ids' => 'required|array',
                'harvest_ids.*' => 'exists:harvests,id',
                'weighing_type' => 'required|in:total,individual',
                'total_weight_kg' => 'required_if:weighing_type,total|numeric|min:0',
                'individual_weights' => 'required_if:weighing_type,individual|array',
                'individual_weights.*' => 'numeric|min:0',
            ]);
            Log::info('Validation successful. Validated data:', $validated);

        } catch (ValidationException $e) {
            Log::error('Validation failed in bulkWeigh:', ['errors' => $e->errors(), 'request' => $request->all()]);
            throw $e; // Re-throw the exception so Inertia can catch it
        }

        $harvestIds = $validated['harvest_ids'];
        $weighingBatchId = 'WB-' . strtoupper(uniqid());
        $now = now();

        // Security check - ensure all harvests belong to user's farm
        $harvests = Harvest::whereIn('id', $harvestIds)->get();
        Log::info('Fetched harvests:', $harvests->pluck('id')->toArray());

        $userFarmId = $user->role === 'super_admin' ? session('active_farm_id') : $user->farm_id;

        foreach ($harvests as $harvest) {
            if ($userFarmId != $harvest->farm_id) {
                Log::warning('Security check failed for harvest ID: ' . $harvest->id . ' by user ID: ' . $user->id);
                abort(403);
            }
        }
        Log::info('Security check passed for all selected harvests.');


        if ($validated['weighing_type'] === 'total') {
            Log::info('Weighing type: total');
            // Distribute total weight proportionally by box count
            $totalWeight = $validated['total_weight_kg'];
            $totalBoxes = $harvests->sum('boxes_count') ?: $harvests->count(); // Fallback to count if boxes_count is 0

            Log::info("Total weight: {$totalWeight}, Total boxes (or harvests count): {$totalBoxes}");

            foreach ($harvests as $harvest) {
                $boxes = $harvest->boxes_count ?: 1; // Ensure boxes is at least 1 to avoid division by zero
                $actualKg = ($totalWeight / $totalBoxes) * $boxes;
                $unitPriceDh = $harvest->unit_price_dh ?? 0;
                $totalRevenue = $actualKg * $unitPriceDh;

                Log::info("Updating harvest ID: {$harvest->id} (boxes: {$boxes}). Calculated actualKg: {$actualKg}, unitPriceDh: {$unitPriceDh}, totalRevenue: {$totalRevenue}");

                $harvest->update([
                    'actual_kg' => $actualKg,
                    'quantity_kg' => $actualKg, // Update quantity to actual
                    'is_weighed' => true,
                    'weighed_at' => $now,
                    'weighing_batch_id' => $weighingBatchId,
                    'total_revenue_dh' => $totalRevenue
                ]);
                Log::info("Harvest ID: {$harvest->id} updated successfully.");
            }
        } else {
            Log::info('Weighing type: individual');
            // Individual weights provided
            $individualWeights = $validated['individual_weights'];
            foreach ($harvests as $harvest) {
                $actualKg = $individualWeights[$harvest->id] ?? $harvest->estimated_kg;
                $unitPriceDh = $harvest->unit_price_dh ?? 0;
                $totalRevenue = $actualKg * $unitPriceDh;

                Log::info("Updating harvest ID: {$harvest->id} (individual weight). Calculated actualKg: {$actualKg}, unitPriceDh: {$unitPriceDh}, totalRevenue: {$totalRevenue}");

                $harvest->update([
                    'actual_kg' => $actualKg,
                    'quantity_kg' => $actualKg,
                    'is_weighed' => true,
                    'weighed_at' => $now,
                    'weighing_batch_id' => $weighingBatchId,
                    'total_revenue_dh' => $totalRevenue
                ]);
                Log::info("Harvest ID: {$harvest->id} updated successfully.");
            }
        }

        Log::info('bulkWeigh method finished. Redirecting back.');
        return redirect()->back()->with('success', count($harvestIds) . ' récolte(s) pesée(s) avec succès.');
    }

    public function destroy(Harvest $harvest, Request $request)
    {
        $user = $request->user();
        $userFarmId = $user->role === 'super_admin' ? session('active_farm_id') : $user->farm_id;
        if ($userFarmId != $harvest->farm_id) {
            abort(403);
        }

        $harvest->delete();

        return redirect()->back()->with('success', 'Récolte supprimée.');
    }
}
