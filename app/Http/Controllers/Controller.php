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
}
