<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id', 'enterprise_id', 'matricule', 'full_name', 'last_name', 'first_name', 'complement', 'cin', 'cnss_number',
        'dob', 'hire_date', 'phone', 'address', 'bank_name', 'rib',
        'base_rate', 'is_active', 'badge_uuid', 'photo_path'
    ];

    protected $casts = [
        // Explicit Y-m-d format (not the plain 'date' cast) — the frontend's <input type="date">
        // and the raw string comparisons in Employees.jsx expect "2000-05-15", not the full
        // ISO8601 datetime a bare 'date' cast serializes to ("2000-05-15T00:00:00.000000Z").
        'dob' => 'date:Y-m-d',
        'hire_date' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function pointageRecords()
    {
        return $this->hasMany(PointageRecord::class);
    }
}
