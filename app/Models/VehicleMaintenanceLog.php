<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleMaintenanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'farm_id',
        'description',
        'performed_at',
        'performed_by_id',
        'next_due_date',
        'created_by',
    ];

    protected $casts = [
        'performed_at' => 'date',
        'next_due_date' => 'date',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function performedBy()
    {
        return $this->belongsTo(Employee::class, 'performed_by_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
