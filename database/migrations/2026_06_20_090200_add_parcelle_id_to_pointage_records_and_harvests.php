<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pointage_records', function (Blueprint $table) {
            if (! Schema::hasColumn('pointage_records', 'parcelle_id')) {
                $table->foreignId('parcelle_id')->nullable()->after('bloc_id')->constrained('parcelles')->nullOnDelete();
            }
        });

        Schema::table('harvests', function (Blueprint $table) {
            if (! Schema::hasColumn('harvests', 'parcelle_id')) {
                $table->foreignId('parcelle_id')->nullable()->after('bloc_id')->constrained('parcelles')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('pointage_records', function (Blueprint $table) {
            if (Schema::hasColumn('pointage_records', 'parcelle_id')) {
                $table->dropForeign(['parcelle_id']);
                $table->dropColumn('parcelle_id');
            }
        });

        Schema::table('harvests', function (Blueprint $table) {
            if (Schema::hasColumn('harvests', 'parcelle_id')) {
                $table->dropForeign(['parcelle_id']);
                $table->dropColumn('parcelle_id');
            }
        });
    }
};
