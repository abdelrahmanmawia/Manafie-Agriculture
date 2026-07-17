<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\ManualStockEntry;
use App\Models\StockAlert;
use App\Models\Product;
use App\Models\Vehicle;
use App\Models\Bloc;
use App\Models\Sector;
use App\Models\Parcelle;
use App\Models\Operation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class StockReportController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Stock/Reports/Index');
    }

    public function inventoryValue(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $inventoryValue = StockInventory::when($farmId, function ($query) use ($farmId) {
                $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId));
            })
            ->with('product')
            ->get()
            ->map(function ($item) {
                $cost = $item->average_cost ?? $item->product->unit_cost ?? 0;
                return [
                    'product_name' => $item->product->name,
                    'quantity_on_hand' => $item->quantity_on_hand,
                    'unit_type' => $item->product->unit_type,
                    'unit_cost' => $cost,
                    'total_value' => $item->quantity_on_hand * $cost,
                ];
            });

        return Inertia::render('Stock/Reports/InventoryValue', [
            'inventoryValue' => $inventoryValue,
            'farms' => $request->user()->role === 'super_admin' ? Farm::all(['id', 'name']) : [],
            'selectedFarmId' => $farmId,
        ]);
    }

    public function movementHistory(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $movements = StockMovement::with('product', 'performedBy', 'bloc', 'sector', 'parcelle', 'vehicle')
            ->when($farmId, function ($query) use ($farmId) {
                $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId));
            })
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Stock/Reports/MovementHistory', [
            'movementHistory' => $movements,
            'farms' => $request->user()->role === 'super_admin' ? Farm::all(['id', 'name']) : [],
            'selectedFarmId' => $farmId,
        ]);
    }

    public function consumptionByOperation(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $consumption = ManualStockEntry::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('entry_type', 'consumption')
            ->whereNotNull('operation_id')
            ->with('product', 'operation', 'bloc', 'sector', 'parcelle')
            ->get()
            ->groupBy('operation.name')
            ->map(function ($entries, $operationName) {
                return [
                    'operation_name' => $operationName,
                    'total_quantity_consumed' => $entries->sum('quantity'),
                    'products_consumed' => $entries->groupBy('product.name')->map(function ($productEntries, $productName) {
                        return [
                            'product_name' => $productName,
                            'quantity' => $productEntries->sum('quantity'),
                            'unit_type' => $productEntries->first()->product->unit_type,
                        ];
                    })->values(),
                ];
            })->values();

        return Inertia::render('Stock/Reports/ConsumptionByOperation', [
            'consumptionByOperation' => $consumption,
            'farms' => $request->user()->role === 'super_admin' ? Farm::all(['id', 'name']) : [],
            'selectedFarmId' => $farmId,
        ]);
    }

    public function costPerHectare(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $costPerHectareData = ManualStockEntry::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('entry_type', 'consumption')
            ->whereNotNull('bloc_id')
            ->whereHas('bloc', function ($query) {
                $query->whereNotNull('area_ha')->where('area_ha', '>', 0);
            })
            ->with('product', 'bloc')
            ->get()
            ->groupBy('bloc.name')
            ->map(function ($entries, $blocName) {
                $totalCost = $entries->sum(function ($entry) {
                    return ($entry->product->unit_cost ?? 0) * $entry->quantity;
                });
                $totalArea = $entries->first()->bloc->area_ha ?? 0;

                return [
                    'bloc_name' => $blocName,
                    'total_cost' => $totalCost,
                    'total_area_hectares' => $totalArea,
                    'cost_per_hectare' => $totalArea > 0 ? $totalCost / $totalArea : 0,
                ];
            })->values();

        return Inertia::render('Stock/Reports/CostPerHectare', [
            'costPerHectareData' => $costPerHectareData,
            'farms' => $request->user()->role === 'super_admin' ? Farm::all(['id', 'name']) : [],
            'selectedFarmId' => $farmId,
        ]);
    }

    public function stockTurnover(Request $request)
    {
        $farmId = $this->scopedFarmId($request);
        $periodInDays = $request->input('period', 365); // Default to 1 year

        $products = Product::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get();

        $stockTurnoverData = $products->map(function ($product) use ($periodInDays) {
            $startDate = now()->subDays($periodInDays);

            $beginningInventory = StockInventory::where('product_id', $product->id)
                ->value('quantity_on_hand') ?? 0; // Simplified: current stock as beginning

            $purchases = StockMovement::where('product_id', $product->id)
                ->where('movement_type', 'in')
                ->where('date', '>=', $startDate)
                ->sum('quantity');

            $salesOrConsumption = StockMovement::where('product_id', $product->id)
                ->where('movement_type', 'out')
                ->where('date', '>=', $startDate)
                ->sum('quantity');

            $endingInventory = $beginningInventory + $purchases - $salesOrConsumption; // Simplified calculation

            $averageInventory = ($beginningInventory + $endingInventory) / 2;

            $costOfGoodsSold = StockMovement::where('product_id', $product->id)
                ->where('movement_type', 'out')
                ->where('date', '>=', $startDate)
                ->sum('total_cost');

            $stockTurnoverRatio = $averageInventory > 0 ? $costOfGoodsSold / ($averageInventory * ($product->unit_cost ?? 1)) : 0; // Using unit cost for value

            return [
                'product_name' => $product->name,
                'beginning_inventory' => $beginningInventory,
                'purchases' => $purchases,
                'sales_consumption' => $salesOrConsumption,
                'ending_inventory' => $endingInventory,
                'average_inventory' => $averageInventory,
                'cost_of_goods_sold' => $costOfGoodsSold,
                'stock_turnover_ratio' => round($stockTurnoverRatio, 2),
                'days_inventory_outstanding' => $stockTurnoverRatio > 0 ? round(365 / $stockTurnoverRatio) : 'N/A',
            ];
        });

        return Inertia::render('Stock/Reports/StockTurnover', [
            'stockTurnoverData' => $stockTurnoverData,
            'farms' => $request->user()->role === 'super_admin' ? Farm::all(['id', 'name']) : [],
            'selectedFarmId' => $farmId,
        ]);
    }

    public function expiryAlerts(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $expiryAlerts = StockAlert::with('product')
            ->when($farmId, function ($query) use ($farmId) {
                $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId));
            })
            ->whereIn('alert_type', ['expired', 'expiring_soon'])
            ->where('is_resolved', false)
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Stock/Reports/ExpiryAlerts', [
            'expiryAlerts' => $expiryAlerts,
            'farms' => $request->user()->role === 'super_admin' ? Farm::all(['id', 'name']) : [],
            'selectedFarmId' => $farmId,
        ]);
    }
}
