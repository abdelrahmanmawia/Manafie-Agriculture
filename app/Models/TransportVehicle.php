<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransportVehicle extends Model
{
    use HasFactory;

    protected $fillable = ['farm_id', 'transport_company_id', 'code', 'driver_name', 'driver_phone', 'capacity', 'fixed_net_per_day', 'is_active'];

    protected $casts = [
        'capacity' => 'integer',
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

    public function employees()
    {
        return $this->hasMany(Employee::class, 'transport_vehicle_id');
    }

    public function attendances()
    {
        return $this->hasMany(TransportVehicleAttendance::class);
    }

    /**
     * This vehicle's daily cost: fixed_net_per_day if a manager set one (a flat rate
     * negotiated with the transport company), otherwise the sum of every currently assigned
     * rider's residence price. Call with employees/residenceLocation already eager-loaded to
     * avoid N+1 queries when computing this across many vehicles (see TransportController::grid()).
     */
    public function netPerDay(): float
    {
        if ($this->fixed_net_per_day !== null) {
            return (float) $this->fixed_net_per_day;
        }

        return (float) $this->employees->sum(fn ($e) => $e->residenceLocation->price_per_person ?? 0);
    }
}
