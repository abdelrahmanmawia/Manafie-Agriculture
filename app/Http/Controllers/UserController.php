<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Enterprise;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->role !== 'super_admin') {
            abort(403);
        }

        $farmId = $request->query('farm_id');
        $enterpriseId = $request->query('enterprise_id');
        
        $query = User::with(['farm', 'enterprise']);

        if ($enterpriseId) {
            $query->where('enterprise_id', $enterpriseId);
        } elseif ($farmId) {
            $query->where('farm_id', $farmId);
        }

        return Inertia::render('Admin/Users', [
            'users' => $query->get(),
            'farms' => \App\Models\Farm::all(),
            'enterprises' => $farmId ? \App\Models\Enterprise::where('farm_id', $farmId)->get() : \App\Models\Enterprise::all(),
            'selectedFarmId' => $farmId,
            'selectedEnterpriseId' => $enterpriseId
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'super_admin', 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:enterprise_admin,data_entry,farm_manager',
            'farm_id' => 'required|exists:farms,id',
            'enterprise_id' => 'nullable|exists:enterprises,id',
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'farm_id' => $validated['farm_id'],
            'enterprise_id' => $validated['enterprise_id'],
        ]);

        return redirect()->back()->with('success', 'User created successfully.');
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless($request->user()->role === 'super_admin', 403);

        if ($user->role === 'super_admin') {
            return redirect()->back()->with('error', 'Cannot delete super admin.');
        }
        
        $user->delete();
        return redirect()->back();
    }
}
