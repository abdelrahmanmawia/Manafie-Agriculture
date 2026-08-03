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
        'model',
        'fuel_type',
        'default_daily_rate',
        'is_location',
        'default_driver_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_location' => 'boolean',
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

    public function usages()
    {
        return $this->hasMany(VehicleUsage::class);
    }
}
