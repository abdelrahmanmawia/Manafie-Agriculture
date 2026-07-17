<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'vehicle_id',
        'product_id',
        'transaction_type',
        'quantity_liters',
        'unit_price_per_liter',
        'total_cost',
        'driver_id',
        'performed_by',
        'date',
        'odometer_km',
        'hours_worked',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'quantity_liters' => 'decimal:2',
        'unit_price_per_liter' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'odometer_km' => 'decimal:2',
        'hours_worked' => 'decimal:2',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
