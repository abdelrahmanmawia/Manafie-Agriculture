<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prestataire extends Model
{
    protected $fillable = [
        'farm_id', 'name', 'service', 'mode_facturation', 'prix_nuit', 'prix_jour', 'forfait',
        'effectif_nuit', 'effectif_jour', 'contrat_debut', 'contrat_fin', 'is_active',
    ];

    protected $casts = [
        'prix_nuit' => 'float',
        'prix_jour' => 'float',
        'forfait' => 'float',
        'effectif_nuit' => 'integer',
        'effectif_jour' => 'integer',
        'contrat_debut' => 'date:Y-m-d',
        'contrat_fin' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function charges()
    {
        return $this->hasMany(ChargeExterne::class);
    }
}
