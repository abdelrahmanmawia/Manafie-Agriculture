<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A wrong réception is cancelled, not deleted: a negative "adjustment" takes its quantity and
 * value back out, and the réception points to it (annulation_id) so it shows as "Annulée" and
 * can't be cancelled twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('annulation_id')->nullable()->after('reference_id')->constrained('stock_movements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('annulation_id');
        });
    }
};
