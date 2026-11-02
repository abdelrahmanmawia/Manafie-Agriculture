<?php

namespace App\Http\Controllers;

use App\Exports\ChargesExternesExport;
use App\Models\ChargeExterne;
use App\Models\Prestataire;
use App\Services\ChargeExterneService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Companies under contract and what each period costs. Their people aren't pointed here: only the
 * totals they send. Farm-wide like transport, so not for a division-locked user. A charge stays
 * editable after its period closes: the invoice often comes later, and it isn't pay.
 */
class ChargeExterneController extends Controller
{
    private const DISK = 'local';

    public function __construct(private ChargeExterneService $charges) {}

    private function farmId(Request $request): int
    {
        abort_if($request->user()->enterprise_id, 403);

        return $this->resolveWriteFarmId($request);
    }

    private function filtres(Request $request, int $farmId): array
    {
        return $request->validate([
            'annee' => 'nullable|integer|min:2000|max:2100',
            'prestataire_id' => ['nullable', Rule::exists('prestataires', 'id')->where('farm_id', $farmId)],
            'service' => 'nullable|string|max:100',
        ]);
    }

    public function index(Request $request)
    {
        $farmId = $this->farmId($request);
        $filtres = $this->filtres($request, $farmId);

        return Inertia::render('Pointage/ChargesExternes', [
            'suivi' => $this->charges->suivi($farmId, $filtres),
            'filtres' => $filtres,
            'prestataires' => Prestataire::where('farm_id', $farmId)->withCount('charges')->orderBy('name')->get(),
            'periodes' => $this->charges->periodes($farmId),
            'statuts' => ChargeExterneService::STATUTS,
            // Opened from a period's "non saisi" line on the Quinzaines page.
            'ajouter' => $request->validate(['ajouter' => ['nullable', 'regex:/^\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}$/']])['ajouter'] ?? null,
        ]);
    }

    public function export(Request $request)
    {
        $farmId = $this->farmId($request);
        $suivi = $this->charges->suivi($farmId, $this->filtres($request, $farmId));

        return Excel::download(new ChargesExternesExport($suivi), "Charges_externes_{$suivi['annee']}.xlsx");
    }

    private function reglesCharge(): array
    {
        return [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'jours_nuit' => 'nullable|integer|min:0|max:100000',
            'jours_jour' => 'nullable|integer|min:0|max:100000',
            'montant' => 'nullable|numeric|min:0',
            'statut_facture' => ['required', Rule::in(array_keys(ChargeExterneService::STATUTS))],
            'facture_numero' => 'nullable|string|max:100',
            'facture_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'document' => 'nullable|file|mimes:xlsx,xls,csv,pdf,jpg,jpeg,png|max:10240',
            'remove_document' => ['sometimes', Rule::in([true, false, 0, 1, '0', '1', 'true', 'false'])],
        ];
    }

    /** Fills the charge from the form, its amount from the contract when none was typed, and saves it. */
    private function enregistrer(Request $request, ChargeExterne $charge, Prestataire $prestataire, array $validated)
    {
        $debut = Carbon::parse($validated['start_date']);
        $fin = Carbon::parse($validated['end_date']);
        if ($debut->diffInDays($fin) >= 31) {
            throw ValidationException::withMessages(['end_date' => 'Une charge couvre 31 jours au plus : saisissez-en une par mois ou par période.']);
        }
        $chevauche = ChargeExterne::where('prestataire_id', $prestataire->id)->where('id', '!=', $charge->id ?? 0)
            ->whereDate('start_date', '<=', $fin->toDateString())->whereDate('end_date', '>=', $debut->toDateString())->first();
        if ($chevauche) {
            throw ValidationException::withMessages(['start_date' => "{$prestataire->name} a déjà une charge sur ces dates : ".$this->charges->labelPeriode($chevauche->start_date, $chevauche->end_date).'.']);
        }

        $charge->fill([
            'farm_id' => $prestataire->farm_id,
            'prestataire_id' => $prestataire->id,
            'start_date' => $debut->toDateString(),
            'end_date' => $fin->toDateString(),
            'jours_nuit' => (int) ($validated['jours_nuit'] ?? 0),
            'jours_jour' => (int) ($validated['jours_jour'] ?? 0),
            'statut_facture' => $validated['statut_facture'],
            'facture_numero' => $validated['facture_numero'] ?? null,
            'facture_date' => $validated['facture_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);
        $this->charges->appliquerContrat($charge, $prestataire);

        // No days and no amount is no charge at all (a flat contract needs no days).
        if (($validated['montant'] ?? null) === null && $charge->forfait === null && $charge->jours_nuit + $charge->jours_jour === 0) {
            throw ValidationException::withMessages(['jours_nuit' => 'Saisissez les jours de la société ou le montant de la facture.']);
        }
        $montant = $validated['montant'] ?? $this->charges->montantContrat($charge);
        if ($montant === null) {
            throw ValidationException::withMessages(['montant' => "Le contrat n'indique pas de prix pour ces jours : saisissez le montant de la facture."]);
        }
        $charge->montant = $montant;

        if ($request->hasFile('document')) {
            $this->supprimerDocument($charge);
            $charge->document_path = $request->file('document')->store('charges-externes', self::DISK);
            $charge->document_name = $request->file('document')->getClientOriginalName();
        } elseif ($request->boolean('remove_document')) {
            $this->supprimerDocument($charge);
        }

        $charge->save();
    }

    private function supprimerDocument(ChargeExterne $charge): void
    {
        if ($charge->document_path) {
            Storage::disk(self::DISK)->delete($charge->document_path);
        }
        $charge->document_path = null;
        $charge->document_name = null;
    }

    public function store(Request $request)
    {
        $farmId = $this->farmId($request);
        $validated = $request->validate($this->reglesCharge() + [
            'prestataire_id' => ['required', Rule::exists('prestataires', 'id')->where('farm_id', $farmId)],
        ]);

        $this->enregistrer($request, new ChargeExterne, Prestataire::findOrFail($validated['prestataire_id']), $validated);

        return redirect()->back()->with('success', 'Charge enregistrée.');
    }

    public function update(Request $request, ChargeExterne $charge)
    {
        $this->farmId($request);
        $this->assertFarmScope($request, $charge->farm_id);

        $this->enregistrer($request, $charge, $charge->prestataire, $request->validate($this->reglesCharge()));

        return redirect()->back()->with('success', 'Charge modifiée.');
    }

    public function destroy(Request $request, ChargeExterne $charge)
    {
        $this->farmId($request);
        $this->assertFarmScope($request, $charge->farm_id);

        $this->supprimerDocument($charge);
        $charge->delete();

        return redirect()->back()->with('success', 'Charge supprimée.');
    }

    /** The company's own file, kept private: it names their people. */
    public function document(Request $request, ChargeExterne $charge)
    {
        $this->farmId($request);
        $this->assertFarmScope($request, $charge->farm_id);
        abort_unless($charge->document_path && Storage::disk(self::DISK)->exists($charge->document_path), 404);

        return Storage::disk(self::DISK)->download($charge->document_path, $charge->document_name);
    }

    private function validerPrestataire(Request $request, int $farmId, ?Prestataire $prestataire = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('prestataires')->where('farm_id', $farmId)->ignore($prestataire?->id)],
            'service' => 'required|string|max:100',
            'mode_facturation' => 'required|in:par_jour,forfait',
            'prix_nuit' => 'nullable|numeric|min:0',
            'prix_jour' => 'nullable|numeric|min:0',
            'forfait' => 'nullable|numeric|min:0',
            'effectif_nuit' => 'nullable|integer|min:0|max:10000',
            'effectif_jour' => 'nullable|integer|min:0|max:10000',
            'contrat_debut' => 'nullable|date',
            'contrat_fin' => 'nullable|date|after_or_equal:contrat_debut',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    public function storePrestataire(Request $request)
    {
        $farmId = $this->farmId($request);

        Prestataire::create($this->validerPrestataire($request, $farmId) + ['farm_id' => $farmId]);

        return redirect()->back()->with('success', 'Prestataire enregistré.');
    }

    /** A new price applies from the next charge: those entered keep the terms they have. */
    public function updatePrestataire(Request $request, Prestataire $prestataire)
    {
        $farmId = $this->farmId($request);
        $this->assertFarmScope($request, $prestataire->farm_id);

        $prestataire->update($this->validerPrestataire($request, $farmId, $prestataire));

        return redirect()->back()->with('success', 'Contrat modifié.');
    }

    public function destroyPrestataire(Request $request, Prestataire $prestataire)
    {
        $this->farmId($request);
        $this->assertFarmScope($request, $prestataire->farm_id);

        if ($prestataire->charges()->exists()) {
            return redirect()->back()->withErrors(['prestataire' => "{$prestataire->name} a des charges enregistrées : désactivez-le plutôt, son historique reste."]);
        }
        $prestataire->delete();

        return redirect()->back()->with('success', 'Prestataire supprimé.');
    }
}
