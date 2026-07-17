<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'order_number',
        'supplier_id',
        'supplier_name',
        'order_date',
        'expected_date',
        'status',
        'total_amount',
        'notes',
        'received_by',
        'received_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'order_date' => 'date',
        'expected_date' => 'date',
        'received_at' => 'datetime',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isReceived()
    {
        return $this->status === 'received';
    }

    public function isPending()
    {
        return in_array($this->status, ['pending', 'ordered']);
    }

    public function isPartial()
    {
        return $this->status === 'partial';
    }
}
