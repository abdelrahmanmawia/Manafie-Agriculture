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
    public function index()
    {
        return Inertia::render('Admin/SuperDashboard', [
            'farms' => Farm::with('enterprises')->get(),
            'enterprises' => Enterprise::withCount(['employees', 'quinzaines'])->get()
        ]);
    }

    public function store(Request $request)
    {
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

    public function settings(Farm $farm)
    {
        return Inertia::render('Admin/FarmSettings', [
            'farm' => $farm->load(['sectors.parcelles', 'parcelles']),
            'operations' => Operation::where('farm_id', $farm->id)->get(),
            'blocs' => Bloc::where('farm_id', $farm->id)->get(),
        ]);
    }

    public function updateSettings(Request $request, Farm $farm)
    {
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
        $request->validate(['name' => 'required|string|max:255', 'abbreviation' => 'nullable|string|max:50']);
        
        Operation::create([
            'name' => $request->name,
            'abbreviation' => $request->abbreviation,
            'farm_id' => $farm->id
        ]);

        return redirect()->back()->with('success', 'Opération ajoutée.');
    }

    public function addBloc(Request $request, Farm $farm)
    {
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

    public function deleteSector(Sector $sector)
    {
        $sector->delete();
        return redirect()->back()->with('success', 'Secteur supprimé.');
    }

    public function deleteParcelle(Parcelle $parcelle)
    {
        $parcelle->delete();
        return redirect()->back()->with('success', 'Parcelle supprimée.');
    }

    public function deleteOperation(Operation $operation)
    {
        $operation->delete();
        return redirect()->back()->with('success', 'Opération supprimée.');
    }

    public function deleteBloc(Bloc $bloc)
    {
        $bloc->delete();
        return redirect()->back()->with('success', 'Bloc supprimé.');
    }

    public function destroy(Farm $farm)
    {
        $farm->delete();
        return redirect()->route('dashboard')->with('success', 'Ferme et toutes les données associées supprimées.');
    }
}
