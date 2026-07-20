<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureFarmSelected
{
    /**
     * Super admins must activate a farm (FarmController::activate) before reaching any
     * Pointage or Stock route. Everyone else is already scoped to their own farm.
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()->role === 'super_admin' && !session('active_farm_id')) {
            return redirect()->route('dashboard')->with('error', 'Veuillez sélectionner une ferme pour continuer.');
        }

        return $next($request);
    }
}
