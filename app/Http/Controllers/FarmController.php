<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\Enterprise;
use App\Models\Operation;
use App\Models\Bloc;
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

    public function settings(Farm $farm)
    {
        return Inertia::render('Admin/FarmSettings', [
            'farm' => $farm,
            'operations' => Operation::where('farm_id', $farm->id)->get(),
            'blocs' => Bloc::where('farm_id', $farm->id)->get(),
        ]);
    }

    public function addOperation(Request $request, Farm $farm)
    {
        $request->validate(['name' => 'required|string|max:255']);
        
        Operation::create([
            'name' => $request->name,
            'farm_id' => $farm->id
        ]);

        return redirect()->back()->with('success', 'Opération ajoutée.');
    }

    public function addBloc(Request $request, Farm $farm)
    {
        $request->validate(['name' => 'required|string|max:255']);
        
        Bloc::create([
            'name' => $request->name,
            'farm_id' => $farm->id
        ]);

        return redirect()->back()->with('success', 'Bloc ajouté.');
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
