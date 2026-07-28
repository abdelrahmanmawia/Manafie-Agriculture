<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockInventory extends Model
{
    use HasFactory;

    protected $table = 'stock_inventory';

    protected $fillable = [
        'product_id',
        'quantity_on_hand',
        'quantity_reserved',
        'last_restock_date',
        'last_count_date',
        'batch_number',
        'expiry_date',
        'average_cost',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:2',
        'quantity_reserved' => 'decimal:2',
        'quantity_available' => 'decimal:2',
        'average_cost' => 'decimal:2',
        'last_restock_date' => 'date',
        'last_count_date' => 'date',
        'expiry_date' => 'date',
    ];

    // withTrashed(): see StockMovement::product() — StockInventory has no farm_id of its
    // own, so whereHas('product', ...) farm-scoping would otherwise silently drop the
    // inventory row for a product that's since been archived.
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
