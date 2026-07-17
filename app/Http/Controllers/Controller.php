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
     * Farm to scope a Stock read/report query by. Super admins may pass ?farm_id=
     * to filter, or omit it to see every farm; everyone else is locked to their own farm.
     */
    protected function scopedFarmId(Request $request): ?int
    {
        if ($request->user()->role === 'super_admin') {
            return $request->query('farm_id') ? (int) $request->query('farm_id') : null;
        }

        return $request->user()->farm_id;
    }

    /**
     * Farm a new Stock record should be written under. Super admins must specify
     * farm_id in the request body; everyone else is locked to their own farm.
     */
    protected function resolveWriteFarmId(Request $request): int
    {
        if ($request->user()->role === 'super_admin') {
            return (int) $request->validate(['farm_id' => 'required|exists:farms,id'])['farm_id'];
        }

        return $request->user()->farm_id;
    }
}
