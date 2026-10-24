<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One change to a pointage day (see PointageRecordObserver). Written once, never edited. */
class PointageChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'pointage_record_id', 'employee_id', 'quinzaine_id', 'date', 'nature', 'action', 'avant', 'apres', 'user_id',
    ];

    protected $casts = [
        'date' => 'date',
        'avant' => 'array',
        'apres' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
