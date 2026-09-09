<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransportCompany extends Model
{
    use HasFactory;

    protected $fillable = ['farm_id', 'name', 'rib', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function vehicles()
    {
        return $this->hasMany(TransportVehicle::class);
    }
}
