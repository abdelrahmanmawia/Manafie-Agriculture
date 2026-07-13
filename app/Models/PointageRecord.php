<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointageRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id', 'quinzaine_id', 'operation_id', 'bloc_id', 'sector_id', 'parcelle_id', 'date',
        'hours', 'is_jf', 'rate', 'brut', 'cnss', 'amo', 'ir', 'net'
    ];

    protected $casts = [
        'date' => 'date',
        'is_jf' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function quinzaine()
    {
        return $this->belongsTo(Quinzaine::class);
    }

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    public function bloc()
    {
        return $this->belongsTo(Bloc::class);
    }

    public function parcelle()
    {
        return $this->belongsTo(Parcelle::class);
    }

    public function sector()
    {
        return $this->belongsTo(Sector::class);
    }
}
