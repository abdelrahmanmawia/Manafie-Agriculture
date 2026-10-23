<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A day this vehicle didn't come (its riders came on their own): no transport cost that day. */
class TransportVehicleAbsence extends Model
{
    use HasFactory;

    protected $fillable = ['transport_vehicle_id', 'date'];

    protected $casts = [
        'date' => 'date',
    ];

    public function transportVehicle()
    {
        return $this->belongsTo(TransportVehicle::class);
    }
}
