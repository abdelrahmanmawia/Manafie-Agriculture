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
        'cost',
        'performed_by_id',
        'next_due_date',
        'created_by',
    ];

    protected $casts = [
        'performed_at' => 'date',
        'next_due_date' => 'date',
        'cost' => 'decimal:2',
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

    // Sorties de stock (pièces) rattachées à cette intervention — voir
    // ManualStockEntry::maintenanceLog() pour le sens inverse.
    public function manualStockEntries()
    {
        return $this->hasMany(ManualStockEntry::class, 'maintenance_log_id');
    }
}
