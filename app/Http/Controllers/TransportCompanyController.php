<?php

namespace App\Http\Controllers;

use App\Models\TransportCompany;
use App\Rules\RibMarocain;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TransportCompanyController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        return Inertia::render('Transport/Companies', [
            'companies' => TransportCompany::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canAccessPointage(), 403);

        $farmId = $this->resolveWriteFarmId($request);

        $request->merge(['rib' => RibMarocain::normaliser($request->input('rib')) ?: null]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('transport_companies', 'name')->where('farm_id', $farmId)],
            'rib' => ['nullable', 'string', 'max:100', new RibMarocain],
        ]);

        TransportCompany::create($validated + ['farm_id' => $farmId]);

        return redirect()->back()->with('success', 'Société de transport créée avec succès.');
    }

    public function update(Request $request, TransportCompany $transportCompany)
    {
        $this->assertFarmScope($request, $transportCompany->farm_id);

        if ($request->has('rib')) {
            $request->merge(['rib' => RibMarocain::normaliser($request->input('rib')) ?: null]);
        }
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('transport_companies', 'name')->where('farm_id', $transportCompany->farm_id)->ignore($transportCompany->id)],
            'rib' => ['nullable', 'string', 'max:100', new RibMarocain($transportCompany->rib)],
            'is_active' => 'boolean',
        ]);

        $transportCompany->update($validated);

        return redirect()->back()->with('success', 'Société de transport mise à jour.');
    }

    public function destroy(Request $request, TransportCompany $transportCompany)
    {
        abort_unless($request->user()->canAccessPointage(), 403);
        $this->assertFarmScope($request, $transportCompany->farm_id);

        if ($transportCompany->vehicles()->exists()) {
            return redirect()->back()->withErrors([
                'transport_company' => 'Cette société possède encore des véhicules et ne peut pas être supprimée. Désactivez-la plutôt.',
            ]);
        }

        $transportCompany->delete();

        return redirect()->back()->with('success', 'Société de transport supprimée avec succès.');
    }
}
