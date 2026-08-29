<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'name',
        'is_vehicle_related',
        'is_active',
    ];

    protected $casts = [
        'is_vehicle_related' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
