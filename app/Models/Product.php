<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'farm_id',
        'name',
        'image',
        'category',
        'unit_type',
        'min_stock_level',
        'unit_cost',
        'is_active',
    ];

    protected $casts = [
        'min_stock_level' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $appends = ['image_url'];

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

    public function getImageUrlAttribute()
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }

    public function isLowStock()
    {
        return $this->current_stock <= $this->min_stock_level;
    }
}
