<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureFarmSelected
{
    /**
     * Super admins must activate a farm (FarmController::activate) before reaching any
     * Pointage or Stock route. Everyone else is normally already scoped to their own farm —
     * but a farm_manager/data_entry account can exist with no farm_id assigned (a setup
     * mistake, not a supported state), and every Stock controller's scopedFarmId() resolves
     * that to null, which every index() query's ->when($farmId, ...) then treats as "no
     * filter" rather than "match nothing" — silently returning every farm's records instead
     * of aborting. Block that here too, rather than relying on each controller to reject a
     * null scope on its own.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user->role === 'super_admin' && !session('active_farm_id')) {
            return redirect()->route('dashboard')->with('error', 'Veuillez sélectionner une ferme pour continuer.');
        }

        if ($user->role !== 'super_admin' && !$user->farm_id) {
            return redirect()->route('dashboard')->with('error', 'Aucune ferme ne vous est assignée. Contactez un administrateur.');
        }

        return $next($request);
    }
}
