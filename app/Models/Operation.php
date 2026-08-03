<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    use HasFactory;

    protected $fillable = ['farm_id', 'name', 'abbreviation', 'unit_rate'];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }
}
