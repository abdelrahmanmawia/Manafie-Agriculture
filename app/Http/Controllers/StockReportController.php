<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\ManualStockEntry;
use App\Models\FuelTransaction;
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
        // to a bloc (FuelTransaction) — both are costs incurred on that bloc. Sector/parcelle are
        // optional on both (a sortie can be logged at just the bloc level), so entries without
        // one are bucketed under a "Non spécifié" placeholder rather than dropped.
        $toRow = fn ($blocId, $blocName, $blocArea, $sectorId, $sectorName, $sectorArea, $parcelleId, $parcelleName, $parcelleArea, $cost) => [
            'bloc_id' => $blocId,
            'bloc_name' => $blocName,
            'bloc_area' => $blocArea,
            'sector_id' => $sectorId,
            'sector_name' => $sectorName,
            'sector_area' => $sectorArea,
            'parcelle_id' => $parcelleId,
            'parcelle_name' => $parcelleName,
            'parcelle_area' => $parcelleArea,
            'cost' => $cost,
        ];

        $manualEntries = ManualStockEntry::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('entry_type', 'consumption')
            ->whereNotNull('bloc_id')
            ->whereHas('bloc', $hasArea)
            ->with('product', 'bloc', 'sector', 'parcelle')
            ->get()
            ->map(fn ($entry) => $toRow(
                $entry->bloc_id, $entry->bloc->name, (float) $entry->bloc->area_ha,
                $entry->sector_id, $entry->sector?->name, (float) ($entry->sector?->area_ha ?? 0),
                $entry->parcelle_id, $entry->parcelle?->name, (float) ($entry->parcelle?->area_ha ?? 0),
                ($entry->product->unit_cost ?? 0) * $entry->quantity
            ));

        $fuelEntries = FuelTransaction::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereNotNull('bloc_id')
            ->whereHas('bloc', $hasArea)
            ->with('bloc', 'sector', 'parcelle')
            ->get()
            ->map(fn ($transaction) => $toRow(
                $transaction->bloc_id, $transaction->bloc->name, (float) $transaction->bloc->area_ha,
                $transaction->sector_id, $transaction->sector?->name, (float) ($transaction->sector?->area_ha ?? 0),
                $transaction->parcelle_id, $transaction->parcelle?->name, (float) ($transaction->parcelle?->area_ha ?? 0),
                (float) ($transaction->total_cost ?? 0)
            ));

        $costPerHa = fn ($cost, $area) => $area > 0 ? $cost / $area : 0;

        $costPerHectareData = $manualEntries->concat($fuelEntries)
            ->groupBy('bloc_id')
            ->map(function ($blocRows, $blocId) use ($costPerHa) {
                $first = $blocRows->first();
                $totalCost = $blocRows->sum('cost');
                $totalArea = $first['bloc_area'];

                $sectors = $blocRows->groupBy(fn ($row) => $row['sector_id'] ?? 'none')
                    ->map(function ($sectorRows, $sectorKey) use ($costPerHa) {
                        $sFirst = $sectorRows->first();
                        $sCost = $sectorRows->sum('cost');
                        $sArea = $sFirst['sector_area'];

                        $parcelles = $sectorRows->groupBy(fn ($row) => $row['parcelle_id'] ?? 'none')
                            ->map(function ($parcelleRows, $parcelleKey) use ($costPerHa) {
                                $pFirst = $parcelleRows->first();
                                $pCost = $parcelleRows->sum('cost');
                                $pArea = $pFirst['parcelle_area'];

                                return [
                                    'parcelle_key' => $parcelleKey,
                                    'parcelle_name' => $pFirst['parcelle_name'] ?? 'Non spécifiée',
                                    'total_cost' => $pCost,
                                    'total_area_hectares' => $pArea,
                                    'cost_per_hectare' => $costPerHa($pCost, $pArea),
                                ];
                            })->values();

                        return [
                            'sector_key' => $sectorKey,
                            'sector_name' => $sFirst['sector_name'] ?? 'Non spécifié',
                            'total_cost' => $sCost,
                            'total_area_hectares' => $sArea,
                            'cost_per_hectare' => $costPerHa($sCost, $sArea),
                            'parcelles' => $parcelles,
                        ];
                    })->values();

                return [
                    'bloc_key' => $blocId,
                    'bloc_name' => $first['bloc_name'],
                    'total_cost' => $totalCost,
                    'total_area_hectares' => $totalArea,
                    'cost_per_hectare' => $costPerHa($totalCost, $totalArea),
                    'sectors' => $sectors,
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

            // Value inventory at CUMP (the actual weighted-average cost paid in), same basis
            // used for every other cost calculation in this module — falling back to the
            // catalog unit_cost only when no réception has ever set a CUMP yet.
            $inventory = StockInventory::where('product_id', $product->id)->first();
            $valuationCost = (float) ($inventory->average_cost ?? $product->unit_cost ?? 1);

            $beginningInventory = (float) ($inventory->quantity_on_hand ?? 0); // Simplified: current stock as beginning

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

            $stockTurnoverRatio = $averageInventory > 0 ? $costOfGoodsSold / ($averageInventory * $valuationCost) : 0;

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
}
