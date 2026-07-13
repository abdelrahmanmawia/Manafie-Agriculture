<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Farm extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'box_weight_kg'];

    protected $casts = [
        'box_weight_kg' => 'float',
    ];

    public function enterprises()
    {
        return $this->hasMany(Enterprise::class);
    }

    public function blocs()
    {
        return $this->hasMany(Bloc::class);
    }

    public function sectors()
    {
        return $this->hasManyThrough(Sector::class, Bloc::class);
    }

    public function parcelles()
    {
        return $this->hasManyThrough(Parcelle::class, Bloc::class);
    }

    public function operations()
    {
        return $this->hasMany(Operation::class);
    }
}
