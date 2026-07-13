<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sector extends Model
{
    use HasFactory;

    protected $fillable = [
        'bloc_id',
        'name',
        'description',
        'area_m2',
        'area_ha',
        'total_trees',
        'spacing',
    ];

    protected $casts = [
        'area_m2' => 'decimal:2',
        'area_ha' => 'decimal:4',
    ];

    public function bloc()
    {
        return $this->belongsTo(Bloc::class);
    }

    public function parcelles()
    {
        return $this->hasMany(Parcelle::class);
    }

    public function pointageRecords()
    {
        return $this->hasManyThrough(PointageRecord::class, Parcelle::class);
    }

    public function harvests()
    {
        return $this->hasManyThrough(Harvest::class, Parcelle::class);
    }
}
