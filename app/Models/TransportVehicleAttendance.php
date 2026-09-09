<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransportVehicleAttendance extends Model
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
