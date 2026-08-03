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
        Schema::table('pointage_records', function (Blueprint $table) {
            // Set only for piece-rate operations (Operation.unit_rate is not null) — the quantity
            // (e.g. linear meters) entered for that day, with net = quantity * unit_rate computed
            // and stored at entry time. Null for normal daily-rate/presence records.
            $table->decimal('quantity', 10, 2)->nullable()->after('hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pointage_records', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};
