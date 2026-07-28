<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'alert_type',
        'threshold_value',
        'current_value',
        'is_resolved',
        'resolved_at',
        'notes',
    ];

    protected $casts = [
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    // withTrashed(): see StockMovement::product() — StockAlert has no farm_id of its own,
    // so whereHas('product', ...) farm-scoping would otherwise silently drop alerts whose
    // product has since been archived.
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    public function scopeLowStock($query)
    {
        return $query->where('alert_type', 'low_stock');
    }

    public function resolve()
    {
        $this->update([
            'is_resolved' => true,
            'resolved_at' => now(),
        ]);
    }
}
