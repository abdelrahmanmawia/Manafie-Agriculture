<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A worker of another farm paid through this one's interim invoice (the fiches' "ASSILAH" table):
 * the site's name, and the monthly gross their invoice is computed from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('site_separe', 100)->nullable()->after('salaire_mensuel');
            $table->decimal('salaire_brut_mensuel', 10, 2)->nullable()->after('site_separe');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['site_separe', 'salaire_brut_mensuel']);
        });
    }
};
