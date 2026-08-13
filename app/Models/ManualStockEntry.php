<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ManualStockEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'product_id',
        'entry_type',
        'quantity',
        'employee_id',
        'vehicle_id',
        'maintenance_log_id',
        'pointage_record_id',
        'operation_id',
        'bloc_id',
        'sector_id',
        'parcelle_id',
        'date',
        'entered_by',
        'notes',
        'is_verified',
        'verified_by',
        'verified_at',
        'odometer_km',
    ];

    protected $casts = [
        'date' => 'date',
        'quantity' => 'decimal:2',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'odometer_km' => 'decimal:2',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    // withTrashed(): see StockMovement::product() — a historical entry should keep showing
    // its product's name even after the product is archived.
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function maintenanceLog(): BelongsTo
    {
        return $this->belongsTo(VehicleMaintenanceLog::class, 'maintenance_log_id');
    }

    public function pointageRecord(): BelongsTo
    {
        return $this->belongsTo(PointageRecord::class);
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

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // Reverse of StockMovement::reference() — the cost of this sortie lives on the movement,
    // not here, since ManualStockEntry no longer stores its own unit_cost.
    public function stockMovement(): HasOne
    {
        return $this->hasOne(StockMovement::class, 'reference_id')->where('reference_type', 'manual_entry');
    }
}
