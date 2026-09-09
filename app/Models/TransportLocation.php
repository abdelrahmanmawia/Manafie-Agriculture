<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransportLocation extends Model
{
    use HasFactory;

    protected $fillable = ['farm_id', 'name', 'price_per_person', 'is_active'];

    protected $casts = [
        'price_per_person' => 'float',
        'is_active' => 'boolean',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class, 'residence_location_id');
    }
}
