<?php

namespace App\Http\Middleware;

use App\Models\Farm;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): string|null
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $activeFarm = null;

        if ($user && $user->role === 'super_admin' && session('active_farm_id')) {
            $activeFarm = Farm::find(session('active_farm_id'));
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? $user->load(['farm', 'enterprise']) : null,
            ],
            'activeFarm' => $activeFarm,
            // Every controller already does redirect()->back()->with('success'/'error', ...)
            // for non-validation notices (a delete succeeded, "select a farm first", etc.) —
            // nothing previously read this, so those messages silently never reached the
            // user. Sharing it here (read once by AuthenticatedLayout's toast) is what makes
            // those existing calls actually work, rather than rewriting ~20 call sites.
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
