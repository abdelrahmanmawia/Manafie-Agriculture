<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quinzaine extends Model
{
    use HasFactory;

    protected $fillable = ['enterprise_id', 'start_date', 'end_date', 'is_closed'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_closed' => 'boolean',
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
