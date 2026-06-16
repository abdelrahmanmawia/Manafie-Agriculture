<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuinzaineSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'quinzaine_id',
        'total_net',
        'total_ttc',
        'total_brut',
        'total_hours',
        'employee_count',
        'summary_data',
    ];

    protected $casts = [
        'summary_data' => 'array',
        'total_net' => 'float',
        'total_ttc' => 'float',
        'total_brut' => 'float',
        'total_hours' => 'float',
    ];

    public function quinzaine()
    {
        return $this->belongsTo(Quinzaine::class);
    }
}
