<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Employee moves from enterprise-scoped to farm-scoped (matching Bloc/Operation, which are
     * already farm-level, not enterprise-level): the real archive data confirmed the same real
     * worker moves between enterprises over time (Persealand -> Baraka Green -> Agri Interim),
     * and CIN (not matricule, which isn't stable across a division's own independent numbering)
     * is the real identity key. This truncates the payroll import tables (Employee/Quinzaine/
     * PointageRecord/QuinzaineSummary) as part of the schema change, since PrsRealDataSeeder
     * fully rebuilds them from the source archive immediately after — there is no live data here
     * to preserve, and the pre-existing (enterprise_id, matricule) uniqueness would otherwise
     * block adding the new (farm_id, cin) constraint against already-duplicated rows.
     */
    public function up(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF;');
        DB::table('pointage_records')->truncate();
        DB::table('quinzaine_summaries')->truncate();
        DB::table('quinzaines')->truncate();
        DB::table('employees')->truncate();
        DB::statement('PRAGMA foreign_keys = ON;');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_enterprise_id_matricule_unique');
            $table->foreignId('farm_id')->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->nullable()->change();
            $table->unique(['farm_id', 'cin']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['farm_id', 'cin']);
            $table->dropConstrainedForeignId('farm_id');
            $table->foreignId('enterprise_id')->nullable(false)->change();
            $table->unique(['enterprise_id', 'matricule']);
        });
    }
};
