<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockConsumption extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_movement_id',
        'pointage_record_id',
        'harvest_id',
        'operation_id',
        'bloc_id',
        'sector_id',
        'parcelle_id',
        'vehicle_id',
        'quantity_per_hectare',
        'area_hectares',
        'notes',
    ];

    protected $casts = [
        'quantity_per_hectare' => 'decimal:2',
        'area_hectares' => 'decimal:2',
    ];

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function pointageRecord(): BelongsTo
    {
        return $this->belongsTo(PointageRecord::class);
    }

    public function harvest(): BelongsTo
    {
        return $this->belongsTo(Harvest::class);
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

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
