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
        Schema::table('employees', function (Blueprint $table) {
            // The same real employee can appear under multiple divisions (enterprises) with
            // the same matricule number — the real production data confirmed this (e.g. the
            // same CIN shows up under both PERSEALAND and AGRI INTERIM in the same period, each
            // with its own local matricule numbering). Scope the uniqueness to the division
            // instead of the whole system.
            $table->dropUnique('employees_matricule_unique');
            $table->unique(['enterprise_id', 'matricule']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['enterprise_id', 'matricule']);
            $table->unique('matricule');
        });
    }
};
