<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bloc extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id', 'name', 'area_m2', 'area_ha'
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function sectors()
    {
        return $this->hasMany(Sector::class);
    }

    public function parcelles()
    {
        return $this->hasMany(Parcelle::class);
    }

    public function harvests()
    {
        return $this->hasMany(Harvest::class);
    }

    public function pointageRecords()
    {
        return $this->hasMany(PointageRecord::class);
    }
}
