<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockAlert;
use App\Models\StockMovement;
use App\Models\Vehicle;
use App\Services\FuelConsumptionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockController extends Controller
{
    /**
     * Display the stock management dashboard.
     *
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        return Inertia::render('Stock/Dashboard', self::summaryFor($farmId));
    }

    /**
     * Stock KPIs + recent activity for a farm — shared by the Stock dashboard itself and by
     * the merged Accueil/FarmDashboard view (see EnterpriseController::farmDashboardExtras()).
     */
    public static function summaryFor(?int $farmId): array
    {
        $products = Product::with('stockInventory')
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('is_active', true)
            ->get();

        $lowStock = $products->filter(fn ($product) => $product->isLowStock());
        $lowStockCount = $lowStock->count();

        // What the magasin holds, at the CUMP (the catalog price only before any réception).
        $valeur = $products->sum(function ($product) {
            $inventory = $product->stockInventory->first();

            return (float) ($inventory?->quantity_on_hand ?? 0) * (float) ($inventory?->average_cost ?? $product->unit_cost ?? 0);
        });

        // This month's sorties (fill-ups included), at what they cost when they went out.
        $sortiesMois = (float) StockMovement::where('movement_type', 'out')
            ->when($farmId, fn ($q) => $q->whereHas('product', fn ($q) => $q->where('farm_id', $farmId)))
            ->whereDate('date', '>=', now()->startOfMonth()->toDateString())
            ->sum('total_cost');

        // This month's fill-ups that don't fit their vehicle's meter or habits.
        $fills = FuelConsumptionService::farmFillUps($farmId);
        $analysis = FuelConsumptionService::analyse($fills);
        $pleinsAVerifier = $fills->filter(fn ($f) => $f->date->gte(now()->startOfMonth()) && ($analysis[$f->id]['alertes'] ?? []))->count();

        $vehicleCount = Vehicle::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('is_active', true)
            ->count();

        $equipmentDownCount = Vehicle::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('asset_type', 'equipment')
            ->where('status', 'in_repair')
            ->count();

        $unresolvedAlertCount = StockAlert::when($farmId, fn ($q) => $q->whereHas('product', fn ($q) => $q->where('farm_id', $farmId)))
            ->where('is_resolved', false)
            ->count();

        $recentMovements = StockMovement::with('product', 'performedBy')
            ->when($farmId, fn ($q) => $q->whereHas('product', fn ($q) => $q->where('farm_id', $farmId)))
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get();

        return [
            'stats' => [
                'products' => $products->count(),
                'lowStock' => $lowStockCount,
                'vehicles' => $vehicleCount,
                'equipmentDown' => $equipmentDownCount,
                'alerts' => $unresolvedAlertCount,
                'valeur' => round($valeur, 2),
                'sortiesMois' => round($sortiesMois, 2),
                'pleinsAVerifier' => $pleinsAVerifier,
            ],
            // What to reorder first: the furthest under their minimum.
            'lowStockList' => $lowStock
                ->sortBy(fn ($p) => $p->current_stock / max((float) $p->min_stock_level, 0.01))
                ->take(8)
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'unit_type' => $p->unit_type, 'quantity' => (float) $p->current_stock, 'min' => (float) $p->min_stock_level])
                ->values(),
            'recentMovements' => $recentMovements,
        ];
    }
}
