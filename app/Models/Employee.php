<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'enterprise_id', 'matricule', 'full_name', 'cin', 'cnss_number', 
        'dob', 'hire_date', 'phone', 'address', 'bank_name', 'rib', 
        'type', 'base_rate'
    ];

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function pointageRecords()
    {
        return $this->hasMany(PointageRecord::class);
    }
}
