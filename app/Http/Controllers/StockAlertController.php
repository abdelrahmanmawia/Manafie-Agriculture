<?php

namespace App\Http\Controllers;

use App\Models\StockAlert;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

class StockAlertController extends Controller
{
    public function index(Request $request)
    {
        $query = StockAlert::with('product')
            ->whereHas('product', function ($q) use ($request) {
                $q->where('farm_id', $request->user()->farm_id);
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

        return Inertia::render('Stock/Alerts/Index', [
            'stockAlerts' => $stockAlerts,
        ]);
    }

    public function resolve(Request $request, StockAlert $alert): JsonResponse
    {
        $alert->update([
            'is_resolved' => true,
            'resolved_at' => now(),
            'notes' => $request->input('notes', $alert->notes), // Allow updating notes on resolve
        ]);

        return response()->json($alert);
    }

    public function unresolvedCount(Request $request): JsonResponse
    {
        $count = StockAlert::where('is_resolved', false)
            ->whereHas('product', function ($q) use ($request) {
                $q->where('farm_id', $request->user()->farm_id);
            })
            ->count();

        return response()->json(['count' => $count]);
    }
}
