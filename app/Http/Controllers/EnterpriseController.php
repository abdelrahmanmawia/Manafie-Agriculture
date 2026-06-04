<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Operation;
use App\Models\Bloc;
use App\Models\Quinzaine;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EnterpriseController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->role === 'super_admin') {
            return Inertia::render('Admin/SuperDashboard', [
                'enterprises' => Enterprise::withCount(['employees', 'quinzaines'])->get()
            ]);
        }

        $enterprise = Enterprise::findOrFail($request->user()->enterprise_id);
        
        return Inertia::render('Admin/Dashboard', [
            'enterprise' => $enterprise,
            'stats' => [
                'employees_count' => Employee::where('enterprise_id', $enterprise->id)->count(),
                'open_quinzaines' => Quinzaine::where('enterprise_id', $enterprise->id)->where('is_closed', false)->count(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'contract_type' => 'required|in:avec_contrat,sans_contrat'
        ]);
        
        Enterprise::create([
            'name' => $request->name,
            'contract_type' => $request->contract_type,
            'settings' => ['currency' => 'DH']
        ]);

        return redirect()->back();
    }

    public function settings(Request $request)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }

        $enterpriseId = $request->user()->enterprise_id ?? $request->query('enterprise_id');
        
        if (!$enterpriseId) return redirect()->route('dashboard');

        $enterprise = Enterprise::findOrFail($enterpriseId);
        $operations = Operation::where('enterprise_id', $enterprise->id)->get();
        $blocs = Bloc::where('enterprise_id', $enterprise->id)->get();
        $quinzaines = Quinzaine::where('enterprise_id', $enterprise->id)->latest()->get();

        return Inertia::render('Admin/Settings', [
            'enterprise' => $enterprise,
            'operations' => $operations,
            'blocs' => $blocs,
            'quinzaines' => $quinzaines,
        ]);
    }

    public function closeQuinzaine(Quinzaine $quinzaine)
    {
        $quinzaine->update(['is_closed' => true]);
        return redirect()->back()->with('success', 'Quinzaine closed successfully.');
    }

    public function addOperation(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255', 'enterprise_id' => 'required']);
        Operation::create([
            'name' => $request->name,
            'enterprise_id' => $request->enterprise_id
        ]);
        return redirect()->back();
    }

    public function addBloc(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255', 'enterprise_id' => 'required']);
        Bloc::create([
            'name' => $request->name,
            'enterprise_id' => $request->enterprise_id
        ]);
        return redirect()->back();
    }

    public function createQuinzaine(Request $request)
    {
        $request->validate([
            'enterprise_id' => 'required',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        Quinzaine::create([
            'enterprise_id' => $request->enterprise_id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'is_closed' => false
        ]);

        return redirect()->back();
    }

    public function deleteOperation(Operation $operation)
    {
        $operation->delete();
        return redirect()->back();
    }

    public function deleteBloc(Bloc $bloc)
    {
        $bloc->delete();
        return redirect()->back();
    }
}
