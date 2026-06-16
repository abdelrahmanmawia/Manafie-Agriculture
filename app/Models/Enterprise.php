<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Enterprise extends Model
{
    use HasFactory;

    protected $fillable = ['farm_id', 'name', 'contract_type', 'default_brut_rate', 'logo', 'settings'];

    protected $casts = [
        'settings' => 'array',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function quinzaines()
    {
        return $this->hasMany(Quinzaine::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
