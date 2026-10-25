<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureDomainAccess
{
    /**
     * Gates the Pointage and Stock route groups by the account's level on that domain
     * (User::acces()): reading (GET) needs consultation, anything that writes needs saisie —
     * so a write route can't be left open by forgetting a check in its controller. A
     * consultation account trying to write from a page gets a message back, not an error page.
     */
    public function handle(Request $request, Closure $next, string $domain)
    {
        $user = $request->user();
        $write = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        if ($write ? $user->peutSaisir($domain) : $user->peutConsulter($domain)) {
            return $next($request);
        }

        if ($write && $user->peutConsulter($domain) && ! $request->expectsJson()) {
            return redirect()->back()->with('error', 'Accès en consultation : vous ne pouvez pas modifier '
                .($domain === 'stock' ? 'le stock.' : 'le pointage.'));
        }

        abort(403);
    }
}
