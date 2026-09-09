<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransportSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'quinzaine_id', 'transport_vehicle_id', 'transport_company_id',
        'vehicle_code', 'company_name', 'net_per_day', 'days_count', 'subtotal', 'employee_count',
    ];

    protected $casts = [
        'net_per_day' => 'float',
        'days_count' => 'integer',
        'subtotal' => 'float',
        'employee_count' => 'integer',
    ];

    public function quinzaine()
    {
        return $this->belongsTo(Quinzaine::class);
    }

    public function transportVehicle()
    {
        return $this->belongsTo(TransportVehicle::class);
    }

    public function transportCompany()
    {
        return $this->belongsTo(TransportCompany::class);
    }
}
