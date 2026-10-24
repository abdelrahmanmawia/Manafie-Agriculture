<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Some staff are paid a fixed monthly salary (e.g. 3 500 or 4 000 DH), not by the day: the
// fiches show them with TOTAL = 26 whatever days they worked. employees.salaire_mensuel holds it;
// SalaireFixeService pays it as one pointage record of nature "salaire_mensuel" in the second
// quinzaine of each month (dated its last day), and their day records still count presence and
// transport but pay nothing. That record shares the date of a possible worked day, so the unique
// day key gains the nature. (The new index is added before the old one is dropped: on MySQL the
// employee_id foreign key needs an index starting with employee_id at all times.)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('salaire_mensuel', 10, 2)->nullable()->after('complement');
        });

        Schema::table('pointage_records', function (Blueprint $table) {
            $table->string('nature', 20)->default('jour')->after('present');
        });
        Schema::table('pointage_records', function (Blueprint $table) {
            $table->unique(['employee_id', 'quinzaine_id', 'date', 'nature'], 'pointage_records_day_nature_unique');
        });
        Schema::table('pointage_records', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'quinzaine_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('pointage_records', function (Blueprint $table) {
            $table->unique(['employee_id', 'quinzaine_id', 'date']);
        });
        Schema::table('pointage_records', function (Blueprint $table) {
            $table->dropUnique('pointage_records_day_nature_unique');
            $table->dropColumn('nature');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('salaire_mensuel');
        });
    }
};
