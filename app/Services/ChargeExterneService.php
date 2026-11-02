<?php

namespace App\Services;

use App\Models\ChargeExterne;
use App\Models\Prestataire;
use App\Models\Quinzaine;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * What the farm pays companies under contract (the security company...): one charge per period
 * from the totals they send, never their people. It adds to the period's cost for the farm
 * (CoutFermeService), and here it's followed over a year and checked against the contract. That
 * check only compares figures the company itself sent: it can't tell who really came.
 */
class ChargeExterneService
{
    public const STATUTS = ['non_recue' => 'Non reçue', 'recue' => 'Reçue', 'payee' => 'Payée'];

    /** A contract ending within this many days is flagged. */
    public const ALERTE_FIN_JOURS = 30;

    private const MOIS = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    /** Keeps the contract's terms on the charge, filling only what it doesn't have yet. */
    public function appliquerContrat(ChargeExterne $charge, Prestataire $prestataire): void
    {
        $termes = $prestataire->mode_facturation === 'forfait'
            ? ['forfait' => $prestataire->forfait]
            : ['prix_nuit' => $prestataire->prix_nuit, 'prix_jour' => $prestataire->prix_jour];
        $termes += ['effectif_nuit' => $prestataire->effectif_nuit, 'effectif_jour' => $prestataire->effectif_jour];

        foreach ($termes as $champ => $valeur) {
            $charge->{$champ} ??= $valeur;
        }
    }

    /** What the contract says the charge should cost; null when it has no price. */
    public function montantContrat(ChargeExterne $charge): ?float
    {
        if ($charge->forfait !== null) {
            return (float) $charge->forfait;
        }
        if (($charge->jours_nuit > 0 && $charge->prix_nuit === null) || ($charge->jours_jour > 0 && $charge->prix_jour === null)
            || ($charge->prix_nuit === null && $charge->prix_jour === null)) {
            return null;
        }

        return round($charge->jours_nuit * (float) $charge->prix_nuit + $charge->jours_jour * (float) $charge->prix_jour, 2);
    }

    public function labelPeriode(Carbon $debut, Carbon $fin): string
    {
        $mois = self::MOIS[$debut->month].' '.$debut->year;
        if ($debut->month === $fin->month && $debut->year === $fin->year) {
            if ($debut->day === 1 && $fin->day === 15) {
                return "1QZ {$mois}";
            }
            if ($debut->day === 16 && $fin->isLastOfMonth()) {
                return "2QZ {$mois}";
            }
            if ($debut->day === 1 && $fin->isLastOfMonth()) {
                return $mois;
            }
        }

        return $debut->format('d/m/Y').' – '.$fin->format('d/m/Y');
    }

    /** The farm's pay periods, newest first: [key, start_date, end_date, label]. */
    public function periodes(int $farmId): Collection
    {
        return Quinzaine::whereHas('enterprise', fn ($q) => $q->where('farm_id', $farmId))
            ->orderByDesc('start_date')->get(['start_date', 'end_date'])
            ->unique(fn ($q) => $this->cle($q->start_date, $q->end_date))
            ->map(fn ($q) => [
                'key' => $this->cle($q->start_date, $q->end_date),
                'start_date' => $q->start_date->toDateString(),
                'end_date' => $q->end_date->toDateString(),
                'label' => $this->labelPeriode($q->start_date, $q->end_date),
            ])->values();
    }

    private function cle(Carbon $debut, Carbon $fin): string
    {
        return $debut->toDateString().'_'.$fin->toDateString();
    }

    /**
     * The charges and the companies missing for these periods, for the farm's cost: [key =>
     * [total, detail, manquantes]]. A charge counts in a period when it has its exact dates.
     *
     * @param  array<string>  $cles  period keys
     */
    public function parPeriode(int $farmId, array $cles): array
    {
        $charges = ChargeExterne::with('prestataire:id,name,service')->where('farm_id', $farmId)->get();
        $prestataires = Prestataire::where('farm_id', $farmId)->where('is_active', true)->get();

        $resultat = [];
        foreach ($cles as $cle) {
            [$debut, $fin] = array_map(fn ($d) => Carbon::parse($d), explode('_', $cle));
            $lignes = $charges->filter(fn ($c) => $this->cle($c->start_date, $c->end_date) === $cle);
            $resultat[$cle] = [
                'total' => round((float) $lignes->sum('montant'), 2),
                'detail' => $lignes->map(fn ($c) => ['prestataire' => $c->prestataire->name, 'service' => $c->prestataire->service, 'montant' => (float) $c->montant])->values()->all(),
                'manquantes' => $this->manquantesPour($debut, $fin, $prestataires, $charges)
                    ->map(fn ($p) => ['id' => $p->id, 'prestataire' => $p->name, 'service' => $p->service])->values()->all(),
            ];
        }

        return $resultat;
    }

    /** The companies under contract with nothing entered for a finished period. */
    private function manquantesPour(Carbon $debut, Carbon $fin, Collection $prestataires, Collection $charges): Collection
    {
        if ($fin->gte(today())) {
            return collect();
        }

        return $prestataires->filter(function ($p) use ($debut, $fin, $charges) {
            $depuis = $p->contrat_debut ?? $p->created_at->copy()->startOfDay();
            if ($fin->lt($depuis) || ($p->contrat_fin && $debut->gt($p->contrat_fin))) {
                return false;
            }

            return $charges->where('prestataire_id', $p->id)
                ->filter(fn ($c) => $c->start_date->lte($fin) && $c->end_date->gte($debut))->isEmpty();
        });
    }

    /**
     * The follow-up of a year: its charges with their checks, the totals, the year before over the
     * same dates (a year still running against a whole one would read as a fall), what's missing,
     * and the contracts ending. A charge belongs to the year it ends in.
     */
    public function suivi(int $farmId, array $filtres): array
    {
        $annee = (int) ($filtres['annee'] ?? today()->year);
        $prestataireId = $filtres['prestataire_id'] ?? null;
        $service = $filtres['service'] ?? null;

        $base = fn () => ChargeExterne::query()->where('farm_id', $farmId)
            ->when($prestataireId, fn ($q) => $q->where('prestataire_id', $prestataireId))
            ->when($service, fn ($q) => $q->whereHas('prestataire', fn ($p) => $p->where('service', $service)));

        $charges = $base()->with('prestataire')->whereYear('end_date', $annee)->orderByDesc('end_date')->get();
        $lignes = $charges->map(fn ($c) => $this->ligne($c))->values();

        $limite = $annee === today()->year ? today()->subYear() : Carbon::create($annee - 1, 12, 31);
        $avant = $base()->whereYear('end_date', $annee - 1)->whereDate('end_date', '<=', $limite->toDateString())->get();
        $montant = round((float) $charges->sum('montant'), 2);
        $joursNuit = (int) $charges->sum('jours_nuit');
        $joursJour = (int) $charges->sum('jours_jour');

        $prestataires = Prestataire::where('farm_id', $farmId)->orderBy('name')->get();
        $concernes = $prestataires->where('is_active', true)
            ->when($prestataireId, fn ($c) => $c->where('id', (int) $prestataireId))
            ->when($service, fn ($c) => $c->where('service', $service));
        $toutes = ChargeExterne::where('farm_id', $farmId)->get();
        $manquantes = $this->periodes($farmId)
            ->filter(fn ($p) => Carbon::parse($p['end_date'])->year === $annee)
            ->flatMap(fn ($p) => $this->manquantesPour(Carbon::parse($p['start_date']), Carbon::parse($p['end_date']), $concernes, $toutes)
                ->map(fn ($c) => ['prestataire_id' => $c->id, 'prestataire' => $c->name, 'service' => $c->service, 'periode' => $p['key'], 'label' => $p['label']]))
            ->values();

        return [
            'annee' => $annee,
            'annees' => $this->annees($farmId),
            'lignes' => $lignes,
            'totaux' => [
                'montant' => $montant,
                'jours_nuit' => $joursNuit,
                'jours_jour' => $joursJour,
                'cout_jour' => $joursNuit + $joursJour > 0 ? round($montant / ($joursNuit + $joursJour), 2) : null,
                'periodes' => $charges->count(),
            ],
            'precedente' => $avant->isEmpty() ? null : [
                'annee' => $annee - 1,
                'jusquau' => $limite->toDateString(),
                'montant' => round((float) $avant->sum('montant'), 2),
                'jours' => (int) $avant->sum('jours_nuit') + (int) $avant->sum('jours_jour'),
            ],
            'parPrestataire' => $charges->groupBy('prestataire_id')->map(fn ($g) => [
                'prestataire' => $g->first()->prestataire->name,
                'service' => $g->first()->prestataire->service,
                'montant' => round((float) $g->sum('montant'), 2),
                'jours' => (int) $g->sum('jours_nuit') + (int) $g->sum('jours_jour'),
            ])->sortByDesc('montant')->values(),
            'manquantes' => $manquantes,
            'contrats' => $this->contratsAVoir($concernes),
        ];
    }

    /** A charge's line with its checks against the contract terms it keeps. */
    private function ligne(ChargeExterne $c): array
    {
        $jours = $c->start_date->diffInDays($c->end_date) + 1;
        $attenduNuit = $c->effectif_nuit !== null ? $c->effectif_nuit * $jours : null;
        $attenduJour = $c->effectif_jour !== null ? $c->effectif_jour * $jours : null;
        $contrat = $this->montantContrat($c);
        $total = $c->jours_nuit + $c->jours_jour;

        return [
            'id' => $c->id,
            'prestataire_id' => $c->prestataire_id,
            'prestataire' => $c->prestataire->name,
            'service' => $c->prestataire->service,
            'start_date' => $c->start_date->toDateString(),
            'end_date' => $c->end_date->toDateString(),
            'periode' => $this->labelPeriode($c->start_date, $c->end_date),
            'jours_nuit' => $c->jours_nuit,
            'jours_jour' => $c->jours_jour,
            'prix_nuit' => $c->prix_nuit,
            'prix_jour' => $c->prix_jour,
            'forfait' => $c->forfait,
            'attendu_nuit' => $attenduNuit,
            'attendu_jour' => $attenduJour,
            'ecart_nuit' => $attenduNuit !== null ? $c->jours_nuit - $attenduNuit : null,
            'ecart_jour' => $attenduJour !== null ? $c->jours_jour - $attenduJour : null,
            'montant' => (float) $c->montant,
            'montant_contrat' => $contrat,
            'ecart_montant' => $contrat !== null ? round((float) $c->montant - $contrat, 2) : null,
            'cout_jour' => $total > 0 ? round((float) $c->montant / $total, 2) : null,
            'statut_facture' => $c->statut_facture,
            'facture_numero' => $c->facture_numero,
            'facture_date' => $c->facture_date?->toDateString(),
            'document_name' => $c->document_name,
            'notes' => $c->notes,
        ];
    }

    private function annees(int $farmId): array
    {
        return ChargeExterne::where('farm_id', $farmId)->pluck('end_date')->map(fn ($d) => $d->year)
            ->merge($this->periodes($farmId)->map(fn ($p) => Carbon::parse($p['end_date'])->year))
            ->push(today()->year)
            ->unique()->sortDesc()->values()->all();
    }

    private function contratsAVoir(Collection $prestataires): array
    {
        return $prestataires->filter(fn ($p) => $p->contrat_fin && $p->contrat_fin->lte(today()->addDays(self::ALERTE_FIN_JOURS)))
            ->map(fn ($p) => ['prestataire' => $p->name, 'contrat_fin' => $p->contrat_fin->toDateString(), 'expire' => $p->contrat_fin->lt(today())])
            ->values()->all();
    }
}
