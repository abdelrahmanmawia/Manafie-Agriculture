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
        'notes',
        'operation_id',
        'bloc_id',
        'sector_id',
        'parcelle_id',
    ];

    protected $casts = [
        'date' => 'date',
        'quantity_liters' => 'decimal:2',
        'unit_price_per_liter' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'odometer_km' => 'decimal:2',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    // withTrashed(): see StockMovement::product() — a historical transaction should keep
    // showing its product's name even after the product is archived.
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function bloc(): BelongsTo
    {
        return $this->belongsTo(Bloc::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function parcelle(): BelongsTo
    {
        return $this->belongsTo(Parcelle::class);
    }
}
