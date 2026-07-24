<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\StockAlert;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

class StockAlertController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $query = StockAlert::with('product')
            ->when($farmId, function ($query) use ($farmId) {
                $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId));
            });

        if ($request->has('is_resolved')) {
            $query->where('is_resolved', $request->boolean('is_resolved'));
        } else {
            // By default, show only unresolved alerts
            $query->where('is_resolved', false);
        }

        if ($request->has('alert_type')) {
            $query->where('alert_type', $request->alert_type);
        }

        $stockAlerts = $query->orderBy('created_at', 'desc')->get();

        // The listing above defaults to unresolved-only, so the resolved count needs its own
        // query — counting on $stockAlerts here would always read 0.
        $resolvedCount = StockAlert::where('is_resolved', true)
            ->when($farmId, function ($query) use ($farmId) {
                $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId));
            })
            ->count();

        return Inertia::render('Stock/Alerts/Index', [
            'stockAlerts' => $stockAlerts,
            'resolvedCount' => $resolvedCount,
        ]);
    }

    public function resolve(Request $request, StockAlert $alert)
    {
        $alert->update([
            'is_resolved' => true,
            'resolved_at' => now(),
            'notes' => $request->input('notes', $alert->notes), // Allow updating notes on resolve
        ]);

        return redirect()->back();
    }

    public function unresolvedCount(Request $request): JsonResponse
    {
        $farmId = $this->scopedFarmId($request);

        $count = StockAlert::where('is_resolved', false)
            ->when($farmId, function ($query) use ($farmId) {
                $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId));
            })
            ->count();

        return response()->json(['count' => $count]);
    }
}
