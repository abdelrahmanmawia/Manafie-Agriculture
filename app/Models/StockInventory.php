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

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function isExpiringSoon($days = 30)
    {
        return $this->expiry_date && $this->expiry_date <= now()->addDays($days);
    }

    public function isExpired()
    {
        return $this->expiry_date && $this->expiry_date < now();
    }
}
