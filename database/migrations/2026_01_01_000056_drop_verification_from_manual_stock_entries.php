<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sorties are no longer verified: the magasinier who records them was also the one verifying
 * them, so the check proved nothing — and the farm wants no checking role on top of the
 * magasinier. A sortie stays editable/deletable by Stock saisie.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (test suite only, always an empty fresh DB) can't drop a column that carries a
        // foreign key — same as migration 000046: there, verified_by is simply left unused.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('manual_stock_entries', function (Blueprint $table) {
                $table->dropConstrainedForeignId('verified_by');
            });
        }
        Schema::table('manual_stock_entries', function (Blueprint $table) {
            $table->dropColumn(['is_verified', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('manual_stock_entries', function (Blueprint $table) {
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
        });
        if (! Schema::hasColumn('manual_stock_entries', 'verified_by')) {
            Schema::table('manual_stock_entries', function (Blueprint $table) {
                $table->foreignId('verified_by')->nullable()->constrained('users');
            });
        }
    }
};
