<?php

use App\Services\FarmDefaultsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Farms created before FarmObserver existed got their default exit/vehicle/equipment types
// lazily, the first time a Stock page was opened (a GET that wrote rows). Those reads are now
// read-only, so give any existing farm that never had a list opened its defaults here. Farms
// that already have a list (renamed or deactivated defaults included) are left untouched.
return new class extends Migration
{
    public function up(): void
    {
        $defaults = app(FarmDefaultsService::class);

        foreach (DB::table('farms')->pluck('id') as $farmId) {
            $defaults->seed($farmId);
        }
    }

    public function down(): void
    {
        // Nothing to undo: these are ordinary rows a farm_manager may already have edited.
    }
};
