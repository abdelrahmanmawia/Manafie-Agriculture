<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransportVehicle extends Model
{
    use HasFactory;

    protected $fillable = ['farm_id', 'transport_company_id', 'code', 'driver_name', 'driver_phone', 'capacity', 'price_per_person', 'fixed_net_per_day', 'is_active'];

    protected $casts = [
        'capacity' => 'integer',
        'price_per_person' => 'float',
        'fixed_net_per_day' => 'float',
        'is_active' => 'boolean',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function transportCompany()
    {
        return $this->belongsTo(TransportCompany::class);
    }

    public function equipes()
    {
        return $this->hasMany(Equipe::class);
    }

    /** Current riders: the members of every équipe riding this vehicle. */
    public function employees()
    {
        return $this->hasManyThrough(Employee::class, Equipe::class, 'transport_vehicle_id', 'equipe_id');
    }

    public function absences()
    {
        return $this->hasMany(TransportVehicleAbsence::class);
    }
}
