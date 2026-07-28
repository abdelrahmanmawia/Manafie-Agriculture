<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockAlert;

class StockAlertService
{
    /**
     * Keep the low_stock alert for a product in sync with its current stock level.
     * Called after every write that changes quantity_on_hand, since alerts were previously
     * never generated automatically (seed data only) and stock could silently sit below
     * min_stock_level with no alert ever appearing.
     */
    public static function syncLowStock(Product $product): void
    {
        $currentStock = (float) $product->stockInventory()->sum('quantity_on_hand');
        $minStock = (float) ($product->min_stock_level ?? 0);

        $existing = StockAlert::where('product_id', $product->id)
            ->where('alert_type', 'low_stock')
            ->where('is_resolved', false)
            ->first();

        if ($currentStock <= $minStock) {
            if ($existing) {
                $existing->update([
                    'threshold_value' => $minStock,
                    'current_value' => $currentStock,
                ]);
            } else {
                StockAlert::create([
                    'product_id' => $product->id,
                    'alert_type' => 'low_stock',
                    'threshold_value' => $minStock,
                    'current_value' => $currentStock,
                    'is_resolved' => false,
                ]);
            }
        } elseif ($existing) {
            // Stock recovered above the threshold — the condition that raised this alert no
            // longer holds, so clear it automatically instead of leaving a stale alert around.
            $existing->resolve();
        }
    }
}
