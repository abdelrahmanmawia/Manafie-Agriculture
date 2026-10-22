<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Same rule as UserController::destroy(): a super_admin account is never deleted —
        // otherwise the only account able to create farms/users could delete itself and lock
        // everyone out of administration. The UI doesn't offer it either (Profile/Edit.jsx).
        abort_if($user->role === 'super_admin', 403, 'Un compte super administrateur ne peut pas être supprimé.');

        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        // Logout must come first — it cycles and saves the remember token, which would re-insert
        // an already-deleted user. A user with stock history is protected by restrict FKs (see
        // UserController::destroy); that used to leave them signed out on a 500, so log them
        // back in and explain instead.
        Auth::logout();

        try {
            $user->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            Auth::login($user);

            return Redirect::route('profile.edit')->with('error', 'Impossible de supprimer ce compte : il a un historique de mouvements de stock.');
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
