<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'movement_type',
        'quantity',
        'unit_cost',
        'total_cost',
        'reference_type',
        'reference_id',
        'performed_by',
        'date',
        'notes',
        'bloc_id',
        'sector_id',
        'parcelle_id',
        'vehicle_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'date' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function bloc()
    {
        return $this->belongsTo(Bloc::class);
    }

    public function sector()
    {
        return $this->belongsTo(Sector::class);
    }

    public function parcelle()
    {
        return $this->belongsTo(Parcelle::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function isInbound()
    {
        return in_array($this->movement_type, ['in', 'production']);
    }

    public function isOutbound()
    {
        return in_array($this->movement_type, ['out', 'transfer', 'loss']);
    }
}
