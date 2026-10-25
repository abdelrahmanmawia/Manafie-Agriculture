<?php

namespace App\Services;

use App\Models\StockInventory;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The stock as it stood on a past day, rebuilt from today's quantity and the movements since
 * (there is no daily snapshot): an "out" counts negative, an adjustment carries its own sign.
 */
class StockHistory
{
    /** What moved after the end of $date, per product (signed). */
    public static function movedAfter(Collection $productIds, Carbon $date): Collection
    {
        return StockMovement::whereIn('product_id', $productIds)
            ->whereDate('date', '>', $date->toDateString())
            ->selectRaw("product_id, SUM(CASE WHEN movement_type = 'out' THEN -quantity ELSE quantity END) as delta")
            ->groupBy('product_id')
            ->pluck('delta', 'product_id')
            ->map(fn ($delta) => (float) $delta);
    }

    /** Each product's quantity at the end of $date (today's when null or not in the past). */
    public static function quantitiesAt(Collection $productIds, ?Carbon $date): Collection
    {
        $now = StockInventory::whereIn('product_id', $productIds)->pluck('quantity_on_hand', 'product_id')->map(fn ($q) => (float) $q);
        if (! $date || $date->gte(Carbon::today())) {
            return $now;
        }
        $after = self::movedAfter($productIds, $date);

        return $now->map(fn ($q, $id) => $q - ($after[$id] ?? 0));
    }
}
