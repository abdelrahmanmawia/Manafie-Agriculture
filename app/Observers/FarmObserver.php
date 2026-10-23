<?php

namespace App\Observers;

use App\Models\Farm;
use App\Services\FarmDefaultsService;

/**
 * Kept out of the Farm model itself (models here hold no behaviour — see CLAUDE.md) but still
 * runs on every creation path: FarmController::store, seeders and tests alike.
 */
class FarmObserver
{
    public function __construct(private FarmDefaultsService $defaults) {}

    public function created(Farm $farm): void
    {
        $this->defaults->seed($farm->id);
    }
}
