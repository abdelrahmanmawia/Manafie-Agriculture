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
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'date' => 'date',
    ];

    // withTrashed(): a movement is a permanent audit record and must still resolve its
    // product even after the product is later archived (soft-deleted) — without this,
    // whereHas('product', ...) farm-scoping silently drops every movement for a deleted
    // product from history views.
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // The source document behind this movement (ManualStockEntry or FuelTransaction),
    // which is where destination context (bloc/sector/parcelle/vehicle) actually lives.
    // Resolves to null for movements with no source document (e.g. a plain réception).
    public function reference()
    {
        return $this->morphTo();
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
