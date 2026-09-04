<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\Enterprise;
use App\Models\Operation;
use App\Models\Bloc;
use App\Models\Sector;
use App\Models\Parcelle;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FarmController extends Controller
{
    /**
     * Every farm-structure action here is either a super_admin-only, whole-app-scope action
     * (creating/deleting a farm), or a farm-scoped one that only that farm's own farm_manager
     * (or a super_admin) may touch — plus a data_entry granted Pointage access
     * (canAccessPointage()), since blocs/sectors/parcelles/operations are what pointage records
     * attribute work to. Never a stock-only data_entry, and never another farm's manager.
     *
     * Unlike Pointage/Stock's own pages, every route here already carries the target farm's id
     * in the URL (route-model-bound $farm) — there's no ambiguity to resolve from session, so a
     * super_admin isn't required to have that farm "active" first, unlike EnterpriseController's
     * settings (which only have an enterprise_id/session to go on, no farm id of their own).
     */
    private function assertFarmManagerAccess(Request $request, int $farmId): void
    {
        $user = $request->user();
        abort_unless($user->canAccessPointage(), 403);

        if ($user->role === 'super_admin') {
            return;
        }

        abort_unless($user->farm_id === $farmId, 403);
    }

    public function index()
    {
        return Inertia::render('Admin/SuperDashboard', [
            'farms' => Farm::with('enterprises')->get(),
            'enterprises' => Enterprise::withCount(['employees', 'quinzaines'])->get()
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'super_admin', 403);

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Farm::create([
            'name' => $request->name,
        ]);

        return redirect()->back()->with('success', 'Farm created successfully.');
    }

    /**
     * Set this farm as the super admin's active farm for the rest of their session.
     * Everything they do in Pointage/Stock is scoped to it until they switch or leave.
     */
    public function activate(Request $request, Farm $farm)
    {
        abort_unless($request->user()->role === 'super_admin', 403);

        session(['active_farm_id' => $farm->id]);

        return redirect()->route('dashboard');
    }

    public function deactivate(Request $request)
    {
        abort_unless($request->user()->role === 'super_admin', 403);

        session()->forget('active_farm_id');

        return redirect()->route('dashboard');
    }

    public function settings(Request $request, Farm $farm)
    {
        $this->assertFarmManagerAccess($request, $farm->id);

        return Inertia::render('Admin/FarmSettings', [
            'farm' => $farm->load(['sectors.parcelles', 'parcelles']),
            'operations' => Operation::where('farm_id', $farm->id)->get(),
            'blocs' => Bloc::where('farm_id', $farm->id)->get(),
        ]);
    }

    public function updateSettings(Request $request, Farm $farm)
    {
        $this->assertFarmManagerAccess($request, $farm->id);

        $request->validate([
            'name' => 'required|string|max:255',
            'box_weight_kg' => 'required|numeric|min:1|max:1000',
        ]);

        $farm->update([
            'name' => $request->name,
            'box_weight_kg' => $request->box_weight_kg,
        ]);

        return redirect()->back()->with('success', 'Paramètres de ferme mis à jour.');
    }

    public function addOperation(Request $request, Farm $farm)
    {
        $this->assertFarmManagerAccess($request, $farm->id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'abbreviation' => 'nullable|string|max:50',
            'unit_rate' => 'nullable|numeric|min:0',
        ]);

        Operation::create([
            'name' => $validated['name'],
            'abbreviation' => $validated['abbreviation'] ?? null,
            'unit_rate' => $validated['unit_rate'] ?? null,
            'farm_id' => $farm->id
        ]);

        return redirect()->back()->with('success', 'Opération ajoutée.');
    }

    /**
     * unit_rate needs its own update path (not just create/delete): it's a piece-rate price
     * ("10 DH per meter") that can legitimately change between periods, and deleting+recreating
     * the Operation to change it would break the FK from existing PointageRecords.
     */
    public function updateOperation(Request $request, Operation $operation)
    {
        $this->assertFarmManagerAccess($request, $operation->farm_id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'abbreviation' => 'nullable|string|max:50',
            'unit_rate' => 'nullable|numeric|min:0',
        ]);

        $operation->update($validated);

        return redirect()->back()->with('success', 'Opération mise à jour.');
    }

    public function addBloc(Request $request, Farm $farm)
    {
        $this->assertFarmManagerAccess($request, $farm->id);

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Bloc::create([
            'name' => $request->name,
            'farm_id' => $farm->id,
        ]);

        return redirect()->back()->with('success', 'Bloc ajouté.');
    }

    public function addSector(Request $request, Farm $farm)
    {
        $this->assertFarmManagerAccess($request, $farm->id);

        $request->validate([
            'bloc_id' => 'required|exists:blocs,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'area_m2' => 'nullable|numeric',
            'area_ha' => 'nullable|numeric',
            'total_trees' => 'nullable|integer',
            'spacing' => 'nullable|string|max:50',
        ]);

        Sector::create([
            'bloc_id' => $request->bloc_id,
            'name' => $request->name,
            'description' => $request->description,
            'area_m2' => $request->area_m2 ?? 0,
            'area_ha' => $request->area_ha ?? 0,
            'total_trees' => $request->total_trees ?? 0,
            'spacing' => $request->spacing,
        ]);

        return redirect()->back()->with('success', 'Secteur ajouté.');
    }

    public function addParcelle(Request $request, Farm $farm)
    {
        $this->assertFarmManagerAccess($request, $farm->id);

        $request->validate([
            'bloc_id' => 'required|exists:blocs,id',
            'sector_id' => 'required|exists:sectors,id',
            'name' => 'required|string|max:255',
            'hass_trees' => 'nullable|integer',
            'fuerte_trees' => 'nullable|integer',
            'lambhass_trees' => 'nullable|integer',
            'zutano_trees' => 'nullable|integer',
            'area_m2' => 'nullable|numeric',
            'area_ha' => 'nullable|numeric',
            'spacing' => 'nullable|string|max:50',
            'total_trees' => 'nullable|integer',
        ]);

        Parcelle::create([
            'bloc_id' => $request->bloc_id,
            'sector_id' => $request->sector_id,
            'name' => $request->name,
            'hass_trees' => $request->hass_trees ?? 0,
            'fuerte_trees' => $request->fuerte_trees ?? 0,
            'lambhass_trees' => $request->lambhass_trees ?? 0,
            'zutano_trees' => $request->zutano_trees ?? 0,
            'area_m2' => $request->area_m2 ?? 0,
            'area_ha' => $request->area_ha ?? 0,
            'spacing' => $request->spacing ?? '6*3',
            'total_trees' => $request->total_trees ?? 0,
        ]);

        return redirect()->back()->with('success', 'Parcelle ajoutée.');
    }

    public function deleteSector(Request $request, Sector $sector)
    {
        $this->assertFarmManagerAccess($request, $sector->bloc->farm_id);

        $sector->delete();
        return redirect()->back()->with('success', 'Secteur supprimé.');
    }

    public function deleteParcelle(Request $request, Parcelle $parcelle)
    {
        $this->assertFarmManagerAccess($request, $parcelle->bloc->farm_id);

        $parcelle->delete();
        return redirect()->back()->with('success', 'Parcelle supprimée.');
    }

    public function deleteOperation(Request $request, Operation $operation)
    {
        $this->assertFarmManagerAccess($request, $operation->farm_id);

        $operation->delete();
        return redirect()->back()->with('success', 'Opération supprimée.');
    }

    public function deleteBloc(Request $request, Bloc $bloc)
    {
        $this->assertFarmManagerAccess($request, $bloc->farm_id);

        $bloc->delete();
        return redirect()->back()->with('success', 'Bloc supprimé.');
    }

    public function destroy(Request $request, Farm $farm)
    {
        abort_unless($request->user()->role === 'super_admin', 403);

        $farmId = $farm->id;
        $farm->delete();

        // If this was the super_admin's active farm, the session pointer now dangles — the
        // dashboard route (EnterpriseController::index) does Farm::findOrFail(active_farm_id)
        // and would 404 on the very next request otherwise.
        if ((int) session('active_farm_id') === $farmId) {
            session()->forget('active_farm_id');
        }

        return redirect()->route('dashboard')->with('success', 'Ferme et toutes les données associées supprimées.');
    }
}
