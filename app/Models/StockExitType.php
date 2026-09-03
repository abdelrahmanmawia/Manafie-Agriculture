<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockExitType extends Model
{
    protected $fillable = [
        'farm_id',
        'key',
        'label',
        'requires_maintenance_log',
        'is_active',
    ];

    protected $casts = [
        'requires_maintenance_log' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }
}
