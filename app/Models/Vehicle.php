<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'asset_type',
        'name',
        'plate_number',
        'serial_number',
        'type',
        'model',
        'fuel_type',
        'status',
        'default_daily_rate',
        'purchase_date',
        'is_location',
        'default_driver_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_location' => 'boolean',
        'purchase_date' => 'date',
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

    public function maintenanceLogs()
    {
        return $this->hasMany(VehicleMaintenanceLog::class);
    }
}
