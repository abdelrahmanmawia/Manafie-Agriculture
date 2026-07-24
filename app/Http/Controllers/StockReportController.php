<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\ManualStockEntry;
use App\Models\FuelTransaction;
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
                // average_cost/unit_cost are decimal-cast attributes, which Eloquent serializes
                // as strings — cast explicitly so the frontend can call .toFixed() on them.
                $cost = (float) ($item->average_cost ?? $item->product->unit_cost ?? 0);
                return [
                    'product_name' => $item->product->name,
                    'quantity_on_hand' => (float) $item->quantity_on_hand,
                    'unit_type' => $item->product->unit_type,
                    'unit_cost' => $cost,
                    'total_value' => (float) $item->quantity_on_hand * $cost,
                ];
            });

        return Inertia::render('Stock/Reports/InventoryValue', [
            'inventoryValue' => $inventoryValue,        ]);
    }

    public function movementHistory(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $movements = StockMovement::with([
                'product',
                'performedBy',
                'reference' => function ($morphTo) {
                    $morphTo->morphWith([
                        ManualStockEntry::class => ['bloc', 'sector', 'parcelle', 'vehicle'],
                        FuelTransaction::class => ['vehicle'],
                    ]);
                },
            ])
            ->when($farmId, function ($query) use ($farmId) {
                $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId));
            })
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Stock/Reports/MovementHistory', [
            'movementHistory' => $movements,        ]);
    }

    public function consumptionByOperation(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        // Merge two sources: field consumption (ManualStockEntry) and fuel fill-ups attributed
        // to an operation (FuelTransaction) — both are "stock consumed for an operation", just
        // recorded through different forms.
        $manualEntries = ManualStockEntry::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('entry_type', 'consumption')
            ->whereNotNull('operation_id')
            ->with('product', 'operation')
            ->get()
            ->filter(fn ($entry) => $entry->operation && $entry->product)
            ->map(fn ($entry) => [
                'operation_name' => $entry->operation->name,
                'product_name' => $entry->product->name,
                'unit_type' => $entry->product->unit_type,
                'quantity' => (float) $entry->quantity,
            ]);

        $fuelEntries = FuelTransaction::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereNotNull('operation_id')
            ->with('product', 'operation')
            ->get()
            ->filter(fn ($transaction) => $transaction->operation && $transaction->product)
            ->map(fn ($transaction) => [
                'operation_name' => $transaction->operation->name,
                'product_name' => $transaction->product->name,
                'unit_type' => $transaction->product->unit_type,
                'quantity' => (float) $transaction->quantity_liters,
            ]);

        $consumption = $manualEntries->concat($fuelEntries)
            ->groupBy('operation_name')
            ->map(function ($entries, $operationName) {
                return [
                    'operation_name' => $operationName,
                    'total_quantity_consumed' => $entries->sum('quantity'),
                    'products_consumed' => $entries->groupBy('product_name')->map(function ($productEntries, $productName) {
                        return [
                            'product_name' => $productName,
                            'quantity' => $productEntries->sum('quantity'),
                            'unit_type' => $productEntries->first()['unit_type'],
                        ];
                    })->values(),
                ];
            })->values();

        return Inertia::render('Stock/Reports/ConsumptionByOperation', [
            'consumptionByOperation' => $consumption,        ]);
    }

    public function costPerHectare(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $hasArea = fn ($query) => $query->whereNotNull('area_ha')->where('area_ha', '>', 0);

        // Merge two sources: field consumption (ManualStockEntry) and fuel fill-ups attributed
        // to a bloc (FuelTransaction) — both are costs incurred on that bloc.
        $manualEntries = ManualStockEntry::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('entry_type', 'consumption')
            ->whereNotNull('bloc_id')
            ->whereHas('bloc', $hasArea)
            ->with('product', 'bloc')
            ->get()
            ->map(fn ($entry) => [
                'bloc_name' => $entry->bloc->name,
                'area_ha' => (float) $entry->bloc->area_ha,
                'cost' => ($entry->product->unit_cost ?? 0) * $entry->quantity,
            ]);

        $fuelEntries = FuelTransaction::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereNotNull('bloc_id')
            ->whereHas('bloc', $hasArea)
            ->with('bloc')
            ->get()
            ->map(fn ($transaction) => [
                'bloc_name' => $transaction->bloc->name,
                'area_ha' => (float) $transaction->bloc->area_ha,
                'cost' => (float) ($transaction->total_cost ?? 0),
            ]);

        $costPerHectareData = $manualEntries->concat($fuelEntries)
            ->groupBy('bloc_name')
            ->map(function ($entries, $blocName) {
                $totalCost = $entries->sum('cost');
                $totalArea = $entries->first()['area_ha'] ?? 0;

                return [
                    'bloc_name' => $blocName,
                    'total_cost' => $totalCost,
                    'total_area_hectares' => $totalArea,
                    'cost_per_hectare' => $totalArea > 0 ? $totalCost / $totalArea : 0,
                ];
            })->values();

        return Inertia::render('Stock/Reports/CostPerHectare', [
            'costPerHectareData' => $costPerHectareData,        ]);
    }

    public function stockTurnover(Request $request)
    {
        $farmId = $this->scopedFarmId($request);
        $periodInDays = $request->input('period', 365); // Default to 1 year

        $products = Product::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get();

        $stockTurnoverData = $products->map(function ($product) use ($periodInDays) {
            $startDate = now()->subDays($periodInDays);

            // ->value() reads the raw DB column (not through Eloquent's decimal cast), so cast
            // explicitly — some drivers return DECIMAL columns as strings.
            $beginningInventory = (float) (StockInventory::where('product_id', $product->id)
                ->value('quantity_on_hand') ?? 0); // Simplified: current stock as beginning

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
            'stockTurnoverData' => $stockTurnoverData,        ]);
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
            'expiryAlerts' => $expiryAlerts,        ]);
    }
}
