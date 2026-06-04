<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    use HasFactory;

    protected $fillable = ['enterprise_id', 'name'];

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }
}
