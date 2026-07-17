<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'farm_id',
        'name',
        'reference_code',
        'barcode',
        'category',
        'unit_type',
        'min_stock_level',
        'max_stock_level',
        'unit_cost',
        'supplier',
        'storage_location',
        'specifications',
        'is_active',
    ];

    protected $casts = [
        'specifications' => 'array',
        'min_stock_level' => 'decimal:2',
        'max_stock_level' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function stockInventory()
    {
        return $this->hasMany(StockInventory::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function purchaseOrderItems()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function stockAlerts()
    {
        return $this->hasMany(StockAlert::class);
    }

    public function fuelTransactions()
    {
        return $this->hasMany(FuelTransaction::class);
    }

    public function getCurrentStockAttribute()
    {
        return $this->stockInventory()->sum('quantity_on_hand');
    }

    public function isLowStock()
    {
        return $this->current_stock <= $this->min_stock_level;
    }
}
