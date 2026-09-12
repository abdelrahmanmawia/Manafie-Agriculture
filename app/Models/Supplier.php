<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['farm_id', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }
}
