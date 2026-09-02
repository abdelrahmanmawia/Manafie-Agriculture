<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureDomainAccess
{
    /**
     * Gates the Pointage-domain and Stock-domain route groups for a data_entry account that
     * hasn't been granted that domain (see User::canAccessPointage()/canAccessStock()) — a
     * magasinier hitting a Pointage route, or a pointeur hitting a Stock route. super_admin/
     * farm_manager are never affected. Runs after farm.selected, so $user always has a
     * resolvable farm by the time this checks.
     */
    public function handle(Request $request, Closure $next, string $domain)
    {
        $user = $request->user();
        $allowed = $domain === 'pointage' ? $user->canAccessPointage() : $user->canAccessStock();

        abort_unless($allowed, 403);

        return $next($request);
    }
}
