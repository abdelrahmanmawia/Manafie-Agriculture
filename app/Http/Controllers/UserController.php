<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Enterprise;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

        $validated = $request->validate($this->accountRules($request) + [
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        User::create($this->accountAttributes($validated) + [
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        return redirect()->back()->with('success', 'Utilisateur créé.');
    }

    /**
     * Edit an existing (non super_admin) account: name, email, role, farm/division and domain
     * access. Its password is changed separately, through resetPassword().
     */
    public function update(Request $request, User $user)
    {
        $this->assertManageable($request, $user);

        $validated = $request->validate($this->accountRules($request) + [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->update($this->accountAttributes($validated) + ['email' => $validated['email']]);

        return redirect()->back()->with('success', 'Utilisateur mis à jour.');
    }

    /**
     * Set a new password for someone who lost theirs (or whose account may be compromised).
     * Their existing sessions end on their next request (the `auth.session` middleware compares
     * the password hash each session was opened with), and their scan-station API tokens are
     * revoked, since those don't go through sessions at all.
     */
    public function resetPassword(Request $request, User $user)
    {
        $this->assertManageable($request, $user);

        $validated = $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();
        $user->tokens()->delete();

        return redirect()->back()->with('success', 'Mot de passe réinitialisé.');
    }

    /** Only a super_admin manages accounts, and never another super_admin's (same as destroy()). */
    private function assertManageable(Request $request, User $user): void
    {
        abort_unless($request->user()->role === 'super_admin', 403);
        abort_if($user->role === 'super_admin', 403, 'Un compte super administrateur ne peut pas être modifié ici.');
    }

    /** Validation shared by store() and update() for everything except email/password. */
    private function accountRules(Request $request): array
    {
        return [
            'name' => 'required|string|max:255',
            'role' => 'required|in:data_entry,farm_manager',
            'farm_id' => 'required|exists:farms,id',
            // The division must belong to the chosen farm — otherwise a user could be pinned to
            // one farm while scoped (by every enterprise_id-first check) to another farm's division.
            'enterprise_id' => ['nullable', Rule::exists('enterprises', 'id')->where('farm_id', $request->input('farm_id'))],
            // Only meaningful for role=data_entry (see User::canAccessPointage()/
            // canAccessStock()) — farm_manager always has full access regardless of what's
            // sent here, so the frontend doesn't even show these fields for that role.
            'can_access_pointage' => 'sometimes|boolean',
            'can_access_stock' => 'sometimes|boolean',
        ];
    }

    private function accountAttributes(array $validated): array
    {
        $isManager = $validated['role'] === 'farm_manager';

        return [
            'name' => $validated['name'],
            'role' => $validated['role'],
            'farm_id' => $validated['farm_id'],
            // A farm_manager is farm-wide by definition. The form only disables the division
            // select for that role, so a division picked before switching role was still sent —
            // and every scope check looks at enterprise_id first, silently locking the "manager"
            // to one division.
            'enterprise_id' => $isManager ? null : ($validated['enterprise_id'] ?? null),
            'can_access_pointage' => (bool) ($validated['can_access_pointage'] ?? false),
            'can_access_stock' => (bool) ($validated['can_access_stock'] ?? false),
        ];
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless($request->user()->role === 'super_admin', 403);

        if ($user->role === 'super_admin') {
            return redirect()->back()->with('error', 'Impossible de supprimer un super administrateur.');
        }

        // Stock movements/sorties keep a restrict FK to the user who entered or verified them
        // (they're the audit trail), so a user with stock history can't be deleted — previously
        // a raw 500.
        try {
            $user->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            return redirect()->back()->with('error', 'Impossible de supprimer cet utilisateur : il a un historique de mouvements de stock.');
        }

        return redirect()->back()->with('success', 'Utilisateur supprimé.');
    }
}
