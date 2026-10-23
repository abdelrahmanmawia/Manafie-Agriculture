<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransportSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'quinzaine_id', 'transport_vehicle_id', 'transport_company_id',
        'vehicle_code', 'company_name', 'net_per_day', 'price_per_person', 'days_count', 'rider_days', 'subtotal', 'employee_count',
    ];

    protected $casts = [
        'net_per_day' => 'float',
        'price_per_person' => 'float',
        'days_count' => 'integer',
        'rider_days' => 'integer',
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
