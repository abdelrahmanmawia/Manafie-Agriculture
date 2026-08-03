<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            // Piece-rate price (e.g. "10 DH per meter" for Fixation Brise Vent). Null means the
            // operation is paid at the employee's normal daily rate, not per-unit.
            $table->decimal('unit_rate', 10, 2)->nullable()->after('abbreviation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropColumn('unit_rate');
        });
    }
};
