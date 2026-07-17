<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'name',
        'plate_number',
        'type',
        'brand',
        'model',
        'year',
        'fuel_type',
        'fuel_capacity_liters',
        'default_driver_id',
        'current_location',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'fuel_capacity_liters' => 'decimal:2',
        'year' => 'integer',
        'is_active' => 'boolean',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function defaultDriver()
    {
        return $this->belongsTo(Employee::class, 'default_driver_id');
    }

    public function fuelTransactions()
    {
        return $this->hasMany(FuelTransaction::class);
    }

    public function manualStockEntries()
    {
        return $this->hasMany(ManualStockEntry::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }
}
