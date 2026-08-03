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
            // Nullable and separate from full_name: the PRS import can populate these directly
            // from the source file's own NOM/PRENOM columns (no lossy split needed for display),
            // while employees created via the manual UI form keep working with full_name only.
            $table->string('last_name')->nullable()->after('full_name');
            $table->string('first_name')->nullable()->after('last_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['last_name', 'first_name']);
        });
    }
};
