<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A chef d'équipe's team — the unit of the first pointage step (Grid "Par équipe"). Its members
 * can belong to different divisions. The vehicle it rides (if any) is how its members travel:
 * TransportVehicle::employees() reads its riders through équipes.
 */
class Equipe extends Model
{
    protected $fillable = ['farm_id', 'name', 'chef_employee_id', 'transport_vehicle_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function chef()
    {
        return $this->belongsTo(Employee::class, 'chef_employee_id');
    }

    public function transportVehicle()
    {
        return $this->belongsTo(TransportVehicle::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
