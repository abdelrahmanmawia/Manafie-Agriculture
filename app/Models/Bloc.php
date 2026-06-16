<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bloc extends Model
{
    use HasFactory;

    protected $fillable = ['farm_id', 'name'];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }
}
