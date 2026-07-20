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
        ];
    }
}
