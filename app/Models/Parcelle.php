<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Parcelle extends Model
{
    use HasFactory;

    protected $fillable = [
        'bloc_id',
        'sector_id',
        'name',
        'hass_trees',
        'fuerte_trees',
        'lambhass_trees',
        'zutano_trees',
        'area_m2',
        'area_ha',
        'spacing',
        'total_trees',
    ];

    protected $casts = [
        'area_m2' => 'decimal:2',
        'area_ha' => 'decimal:4',
    ];

    public function bloc()
    {
        return $this->belongsTo(Bloc::class);
    }

    public function sector()
    {
        return $this->belongsTo(Sector::class);
    }

    public function pointageRecords()
    {
        return $this->hasMany(PointageRecord::class);
    }

    public function harvests()
    {
        return $this->hasMany(Harvest::class);
    }
}
