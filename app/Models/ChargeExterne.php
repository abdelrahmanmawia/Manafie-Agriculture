<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargeExterne extends Model
{
    protected $table = 'charges_externes';

    protected $fillable = [
        'farm_id', 'prestataire_id', 'start_date', 'end_date', 'jours_nuit', 'jours_jour',
        'prix_nuit', 'prix_jour', 'forfait', 'effectif_nuit', 'effectif_jour', 'montant',
        'statut_facture', 'facture_numero', 'facture_date', 'document_path', 'document_name', 'notes',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'facture_date' => 'date:Y-m-d',
        'jours_nuit' => 'integer',
        'jours_jour' => 'integer',
        'prix_nuit' => 'float',
        'prix_jour' => 'float',
        'forfait' => 'float',
        'effectif_nuit' => 'integer',
        'effectif_jour' => 'integer',
        'montant' => 'float',
    ];

    public function prestataire()
    {
        return $this->belongsTo(Prestataire::class);
    }
}
