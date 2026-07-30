<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Farm to scope a Stock/Pointage read query by. Super admins work within whichever
     * farm they activated (see FarmController::activate), stored in session; the
     * EnsureFarmSelected middleware guarantees this is set before a super_admin reaches
     * any Pointage/Stock route. Everyone else is locked to their own farm.
     */
    protected function scopedFarmId(Request $request): ?int
    {
        if ($request->user()->role === 'super_admin') {
            return session('active_farm_id') ? (int) session('active_farm_id') : null;
        }

        return $request->user()->farm_id;
    }

    /**
     * Farm a new Stock record should be written under: the super admin's active farm,
     * or the user's own farm for everyone else.
     */
    protected function resolveWriteFarmId(Request $request): int
    {
        if ($request->user()->role === 'super_admin') {
            abort_unless(session('active_farm_id'), 403, 'Veuillez sélectionner une ferme.');

            return (int) session('active_farm_id');
        }

        return $request->user()->farm_id;
    }

    /**
     * Validate a requested ?enterprise_id against the resolved farm scope before trusting it.
     * Several Pointage/Analytics/Payroll queries only apply their farm_id filter "when no
     * enterprise_id is given" — without this check, an unvalidated enterprise_id belonging to a
     * different farm would bypass that filter entirely and leak the other farm's data straight
     * through. A user with their own fixed enterprise_id is always confined to it regardless of
     * what the query string says.
     */
    protected function scopedEnterpriseId(Request $request, ?int $farmId): ?int
    {
        if ($request->user()->enterprise_id) {
            return $request->user()->enterprise_id;
        }

        $enterpriseId = $request->query('enterprise_id');
        if (!$enterpriseId) {
            return null;
        }

        $enterprise = \App\Models\Enterprise::find($enterpriseId);
        abort_unless($enterprise && $enterprise->farm_id === $farmId, 403);

        return (int) $enterpriseId;
    }
}
