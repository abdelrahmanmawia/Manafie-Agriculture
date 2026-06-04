<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Enterprise extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'contract_type', 'logo', 'settings'];

    protected $casts = [
        'settings' => 'array',
    ];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function operations()
    {
        return $this->hasMany(Operation::class);
    }

    public function blocs()
    {
        return $this->hasMany(Bloc::class);
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
