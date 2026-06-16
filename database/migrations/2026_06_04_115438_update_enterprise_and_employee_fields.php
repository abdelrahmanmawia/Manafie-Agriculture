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
        Schema::table('enterprises', function (Blueprint $table) {
            $table->decimal('default_brut_rate', 10, 2)->default(0)->after('name');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('complement', 10, 2)->default(0)->after('full_name');
        });

        Schema::table('pointage_records', function (Blueprint $table) {
            $table->boolean('is_jf')->default(false)->after('hours'); // Jour Férié
        });
    }

    public function down(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->dropColumn('default_brut_rate');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('complement');
        });

        Schema::table('pointage_records', function (Blueprint $table) {
            $table->dropColumn('is_jf');
        });
    }
};
