<?php

namespace App\Http\Controllers;

use App\Models\ManualStockEntry;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\StockMovement;
use App\Models\Vehicle;
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

        $lowStockCount = $products->filter(fn ($product) => $product->isLowStock())->count();

        $vehicleCount = Vehicle::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('is_active', true)
            ->count();

        $equipmentDownCount = Vehicle::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('asset_type', 'equipment')
            ->where('status', 'in_repair')
            ->count();

        $recentAlerts = StockAlert::with('product')
            ->when($farmId, fn ($q) => $q->whereHas('product', fn ($q) => $q->where('farm_id', $farmId)))
            ->where('is_resolved', false)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        $unresolvedAlertCount = StockAlert::when($farmId, fn ($q) => $q->whereHas('product', fn ($q) => $q->where('farm_id', $farmId)))
            ->where('is_resolved', false)
            ->count();

        $recentMovements = StockMovement::with('product', 'performedBy')
            ->when($farmId, fn ($q) => $q->whereHas('product', fn ($q) => $q->where('farm_id', $farmId)))
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get();

        $pendingManualEntries = ManualStockEntry::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('is_verified', false)
            ->count();

        return [
            'stats' => [
                'products' => $products->count(),
                'lowStock' => $lowStockCount,
                'vehicles' => $vehicleCount,
                'equipmentDown' => $equipmentDownCount,
                'alerts' => $unresolvedAlertCount,
            ],
            'recentAlerts' => $recentAlerts,
            'recentMovements' => $recentMovements,
            'pendingManualEntries' => $pendingManualEntries,
        ];
    }
}
