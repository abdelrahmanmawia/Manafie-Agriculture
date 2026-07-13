<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Harvest extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'bloc_id',
        'sector_id',
        'parcelle_id',
        'date',
        'variety',
        'quantity_kg',
        'estimated_kg',
        'actual_kg',
        'boxes_count',
        'grade',
        'unit_price_dh',
        'total_revenue_dh',
        'comments',
        'is_weighed',
        'weighed_at',
        'weighing_batch_id'
    ];

    protected $casts = [
        'date' => 'date',
        'quantity_kg' => 'float',
        'estimated_kg' => 'float',
        'actual_kg' => 'float',
        'boxes_count' => 'integer',
        'unit_price_dh' => 'float',
        'total_revenue_dh' => 'float',
        'is_weighed' => 'boolean',
        'weighed_at' => 'datetime',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function bloc()
    {
        return $this->belongsTo(Bloc::class);
    }

    public function parcelle()
    {
        return $this->belongsTo(Parcelle::class);
    }

    public function sector()
    {
        return $this->belongsTo(Sector::class);
    }
}
