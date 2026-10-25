<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access is per domain, at one of three levels — aucun, consultation (read and export), saisie
 * (everything) — instead of a yes/no flag that only meant anything for data_entry while a
 * farm_manager wrote everywhere. The pointeur has saisie on Pointage, the magasinier on Stock;
 * the responsable (farm_manager) consults both unless given more. A super_admin has everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('acces_pointage', 15)->default('aucun')->after('enterprise_id');
            $table->string('acces_stock', 15)->default('aucun')->after('acces_pointage');
        });

        foreach (DB::table('users')->get(['id', 'role', 'can_access_pointage', 'can_access_stock']) as $user) {
            [$pointage, $stock] = match ($user->role) {
                'super_admin' => ['saisie', 'saisie'],
                'farm_manager' => ['consultation', 'consultation'],
                default => [$user->can_access_pointage ? 'saisie' : 'aucun', $user->can_access_stock ? 'saisie' : 'aucun'],
            };
            DB::table('users')->where('id', $user->id)->update(['acces_pointage' => $pointage, 'acces_stock' => $stock]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_access_pointage', 'can_access_stock']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_access_pointage')->default(true)->after('enterprise_id');
            $table->boolean('can_access_stock')->default(true)->after('can_access_pointage');
        });

        DB::table('users')->update([
            'can_access_pointage' => DB::raw("CASE WHEN acces_pointage = 'aucun' THEN 0 ELSE 1 END"),
            'can_access_stock' => DB::raw("CASE WHEN acces_stock = 'aucun' THEN 0 ELSE 1 END"),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['acces_pointage', 'acces_stock']);
        });
    }
};
