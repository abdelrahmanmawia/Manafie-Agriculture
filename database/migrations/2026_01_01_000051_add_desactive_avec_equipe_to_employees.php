<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deactivating an équipe deactivates its active members; this marks the ones it deactivated, so
 * reactivating the équipe brings back exactly them — not a member deactivated on their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('desactive_avec_equipe')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('desactive_avec_equipe');
        });
    }
};
