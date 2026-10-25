<?php

namespace App\Http\Controllers;

use App\Models\Bloc;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\Operation;
use App\Models\Parcelle;
use App\Models\Product;
use App\Models\Sector;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceLog;
use App\Services\FuelConsumptionService;
use App\Services\StockHistory;
use App\Services\StockReportPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/**
 * Every report covers a period (StockReportPeriod: a month by default, a quinzaine, two dates or
 * everything) passed to the page as `period` for its picker.
 */
class StockReportController extends Controller
{
    private function period(Request $request): StockReportPeriod
    {
        return StockReportPeriod::fromInput($request->only('periode', 'mois', 'quinzaine', 'from', 'to'));
    }

    private function quantitiesAt(Collection $productIds, ?Carbon $date): Collection
    {
        return StockHistory::quantitiesAt($productIds, $date);
    }

    public function index(Request $request)
    {
        return Inertia::render('Stock/Reports/Index');
    }

    public function inventoryValue(Request $request)
    {
        $farmId = $this->scopedFarmId($request);
        $period = $this->period($request);
        // The stock as it stood at the end of the period (today for an open one), valued at
        // today's CUMP — there is no CUMP history to value it at that day's cost.
        $at = $period->to && $period->to->lt(Carbon::today()) ? $period->to : null;

        $inventory = StockInventory::when($farmId, function ($query) use ($farmId) {
            $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId));
        })
            ->with('product')
            ->get();
        $quantities = $this->quantitiesAt($inventory->pluck('product_id'), $at);

        $inventoryValue = $inventory->map(function ($item) use ($quantities) {
            // average_cost/unit_cost are decimal-cast attributes, which Eloquent serializes
            // as strings — cast explicitly so the frontend can call .toFixed() on them.
            $cost = (float) ($item->average_cost ?? $item->product->unit_cost ?? 0);
            $quantity = (float) ($quantities[$item->product_id] ?? 0);

            return [
                'product_name' => $item->product->name,
                'quantity_on_hand' => $quantity,
                'unit_type' => $item->product->unit_type,
                'unit_cost' => $cost,
                'total_value' => $quantity * $cost,
            ];
        })->values();

        return Inertia::render('Stock/Reports/InventoryValue', [
            'inventoryValue' => $inventoryValue,
            'period' => $period->toArray(),
            'asOf' => ($at ?? Carbon::today())->toDateString(),
        ]);
    }

    public function movementHistory(Request $request)
    {
        $farmId = $this->scopedFarmId($request);
        $period = $this->period($request);
        $type = in_array($request->input('movement_type'), ['in', 'out', 'adjustment'], true) ? $request->input('movement_type') : null;

        $scoped = $period->apply(StockMovement::query()
            ->when($farmId, fn ($query) => $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId))));

        // Over the whole period, whatever the type filter and the page shown.
        $stats = (clone $scoped)
            ->selectRaw('movement_type, COUNT(*) as count, COALESCE(SUM(total_cost), 0) as value')
            ->groupBy('movement_type')
            ->get()
            ->keyBy('movement_type')
            ->map(fn ($row) => ['count' => (int) $row->count, 'value' => (float) $row->value]);

        $movements = $scoped->with([
            'product',
            'performedBy',
            'reference' => function ($morphTo) {
                $morphTo->morphWith([
                    ManualStockEntry::class => ['bloc', 'sector', 'parcelle', 'vehicle', 'employee'],
                    FuelTransaction::class => ['vehicle'],
                ]);
            },
        ])
            ->when($type, fn ($q) => $q->where('movement_type', $type))
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Stock/Reports/MovementHistory', [
            'movementHistory' => $movements,
            'stats' => $stats,
            'movementType' => $type,
            'period' => $period->toArray(),
        ]);
    }

    public function consumptionByOperation(Request $request)
    {
        $farmId = $this->scopedFarmId($request);
        $period = $this->period($request);

        // Merge two sources: field consumption (ManualStockEntry) and fuel fill-ups attributed
        // to an operation (FuelTransaction) — both are "stock consumed for an operation", just
        // recorded through different forms.
        $manualEntries = $period->apply(ManualStockEntry::query())
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
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

        $fuelEntries = $period->apply(FuelTransaction::query())
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
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
            'consumptionByOperation' => $consumption,
            'period' => $period->toArray(),
        ]);
    }

    public function costPerHectare(Request $request)
    {
        $farmId = $this->scopedFarmId($request);
        $period = $this->period($request);

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

        $manualEntries = $period->apply(ManualStockEntry::query())
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('entry_type', 'consumption')
            ->whereNotNull('bloc_id')
            ->whereHas('bloc', $hasArea)
            ->with('stockMovement', 'bloc', 'sector', 'parcelle')
            ->get()
            ->map(fn ($entry) => $toRow(
                $entry->bloc_id, $entry->bloc->name, (float) $entry->bloc->area_ha,
                $entry->sector_id, $entry->sector?->name, (float) ($entry->sector?->area_ha ?? 0),
                $entry->parcelle_id, $entry->parcelle?->name, (float) ($entry->parcelle?->area_ha ?? 0),
                // What the sortie cost (at the CUMP, as recorded), not today's catalog price.
                (float) ($entry->stockMovement->total_cost ?? 0)
            ));

        $fuelEntries = $period->apply(FuelTransaction::query())
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
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
            'costPerHectareData' => $costPerHectareData,
            'period' => $period->toArray(),
        ]);
    }

    public function costPerVehicle(Request $request)
    {
        $farmId = $this->scopedFarmId($request);
        $period = $this->period($request);

        $vehicles = Vehicle::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->orderBy('name')
            ->get();

        $fuelStats = $period->apply(FuelTransaction::query())
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereNotNull('vehicle_id')
            ->selectRaw('vehicle_id, SUM(total_cost) as fuel_cost, SUM(quantity_liters) as fuel_liters, COUNT(*) as fuel_count')
            ->groupBy('vehicle_id')
            ->get()
            ->keyBy('vehicle_id');

        // Every entry_type counts here (consommation, perte, vol, dommage, maintenance...) —
        // all of them represent stock value that left the magasin attributed to this vehicle,
        // matching what Vehicles/Show.jsx already lists under "Historique de Consommation".
        $partsStats = $period->apply(ManualStockEntry::query())
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereNotNull('vehicle_id')
            ->with('stockMovement')
            ->get()
            ->groupBy('vehicle_id')
            ->map(fn ($entries) => [
                'parts_cost' => $entries->sum(fn ($entry) => (float) ($entry->stockMovement->total_cost ?? 0)),
                'parts_count' => $entries->count(),
            ]);

        // Consumption over the period's fill-ups, each rated against the one before it (even
        // when that one is older than the period).
        $allFills = FuelConsumptionService::farmFillUps($farmId);
        $analysis = FuelConsumptionService::analyse($allFills);
        $periodFills = $allFills->filter(fn ($f) => (! $period->from || $f->date->gte($period->from)) && (! $period->to || $f->date->lte($period->to)))
            ->groupBy('vehicle_id');

        $maintenanceStats = $period->apply(VehicleMaintenanceLog::query(), 'performed_at')
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->selectRaw('vehicle_id, SUM(cost) as maintenance_cost, COUNT(*) as maintenance_count')
            ->groupBy('vehicle_id')
            ->get()
            ->keyBy('vehicle_id');

        $costPerVehicleData = $vehicles->map(function ($vehicle) use ($fuelStats, $partsStats, $maintenanceStats, $periodFills, $analysis) {
            $fills = $periodFills->get($vehicle->id, collect());
            $fuel = $fuelStats->get($vehicle->id);
            $parts = $partsStats->get($vehicle->id);
            $maintenance = $maintenanceStats->get($vehicle->id);

            $fuelCost = (float) ($fuel->fuel_cost ?? 0);
            $partsCost = (float) ($parts['parts_cost'] ?? 0);
            $maintenanceCost = (float) ($maintenance->maintenance_cost ?? 0);

            return [
                'vehicle_id' => $vehicle->id,
                'vehicle_name' => $vehicle->name,
                'asset_type' => $vehicle->asset_type,
                'type' => $vehicle->type,
                'plate_number' => $vehicle->plate_number,
                'serial_number' => $vehicle->serial_number,
                'is_active' => $vehicle->is_active,
                'fuel_cost' => $fuelCost,
                'fuel_liters' => (float) ($fuel->fuel_liters ?? 0),
                'fuel_count' => (int) ($fuel->fuel_count ?? 0),
                'parts_cost' => $partsCost,
                'parts_count' => (int) ($parts['parts_count'] ?? 0),
                'maintenance_cost' => $maintenanceCost,
                'maintenance_count' => (int) ($maintenance->maintenance_count ?? 0),
                'total_cost' => $fuelCost + $partsCost + $maintenanceCost,
                'consommation' => $fills->isEmpty() ? null : FuelConsumptionService::average($fills, $analysis),
                'consommation_unite' => FuelConsumptionService::rateLabel(FuelConsumptionService::unitFor($vehicle->type)),
                'pleins_a_verifier' => $fills->filter(fn ($f) => $analysis[$f->id]['alertes'] ?? [])->count(),
            ];
        })
            ->sortByDesc('total_cost')
            ->values();

        return Inertia::render('Stock/Reports/CostPerVehicle', [
            'costPerVehicleData' => $costPerVehicleData,
            'period' => $period->toArray(),
        ]);
    }

    public function stockTurnover(Request $request)
    {
        $farmId = $this->scopedFarmId($request);
        $period = $this->period($request);

        $products = Product::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get();
        $productIds = $products->pluck('id');

        // "Toute la période" starts at the first movement; nothing is counted past today.
        $from = $period->from ?? Carbon::parse(StockMovement::whereIn('product_id', $productIds)->min('date') ?? Carbon::today())->startOfDay();
        $to = $period->to && $period->to->lt(Carbon::today()) ? $period->to : Carbon::today();
        $days = max(1, $from->diffInDays($to) + 1);

        // Pulled once for every product up front instead of per product in the loop.
        $inventories = StockInventory::whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $beginning = $this->quantitiesAt($productIds, $from->copy()->subDay());
        $ending = $this->quantitiesAt($productIds, $to);

        $movementSums = StockMovement::whereIn('product_id', $productIds)
            // A cancelled réception bought nothing (its cancelling adjustment evens the quantities).
            ->where(fn ($q) => $q->where('movement_type', '!=', 'in')->orWhereNull('annulation_id'))
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->selectRaw('product_id, movement_type, SUM(quantity) as total_quantity, SUM(total_cost) as total_cost_sum')
            ->groupBy('product_id', 'movement_type')
            ->get()
            ->groupBy('product_id');

        $stockTurnoverData = $products->map(function ($product) use ($inventories, $movementSums, $beginning, $ending, $days) {
            // Valued at CUMP (the actual weighted-average cost paid in), like every other cost in
            // this module — the catalog unit_cost only when no réception has set a CUMP yet.
            $inventory = $inventories->get($product->id);
            $valuationCost = (float) ($inventory->average_cost ?? $product->unit_cost ?? 1);

            // Both ends are the stock as it stood that day, rebuilt from the movements (counts
            // and opening-stock adjustments included).
            $beginningInventory = (float) ($beginning[$product->id] ?? 0);
            $endingInventory = (float) ($ending[$product->id] ?? 0);

            $productMovements = $movementSums->get($product->id, collect());
            $purchases = (float) ($productMovements->firstWhere('movement_type', 'in')->total_quantity ?? 0);
            $outRow = $productMovements->firstWhere('movement_type', 'out');
            $salesOrConsumption = (float) ($outRow->total_quantity ?? 0);
            $costOfGoodsSold = (float) ($outRow->total_cost_sum ?? 0);

            $averageInventory = ($beginningInventory + $endingInventory) / 2;

            // valuationCost can be a real, explicit 0 (a product recorded as free) — a turnover
            // ratio is meaningless for it, same as with no average inventory to move.
            $stockTurnoverRatio = ($averageInventory > 0 && $valuationCost > 0)
                ? $costOfGoodsSold / ($averageInventory * $valuationCost)
                : 0;

            return [
                'product_name' => $product->name,
                'beginning_inventory' => $beginningInventory,
                'purchases' => $purchases,
                'sales_consumption' => $salesOrConsumption,
                'ending_inventory' => $endingInventory,
                'average_inventory' => $averageInventory,
                'cost_of_goods_sold' => $costOfGoodsSold,
                'stock_turnover_ratio' => round($stockTurnoverRatio, 2),
                // Days of stock at the period's pace of consumption.
                'days_inventory_outstanding' => $stockTurnoverRatio > 0 ? round($days / $stockTurnoverRatio) : 'N/A',
            ];
        });

        return Inertia::render('Stock/Reports/StockTurnover', [
            'stockTurnoverData' => $stockTurnoverData,
            'period' => $period->toArray(),
            'days' => $days,
        ]);
    }
}
