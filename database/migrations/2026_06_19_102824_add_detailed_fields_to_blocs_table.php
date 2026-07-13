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
        Schema::table('blocs', function (Blueprint $table) {
            $table->decimal('area_m2', 12, 2)->default(0);
            $table->decimal('area_ha', 8, 4)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blocs', function (Blueprint $table) {
            $table->dropColumn([
                'area_m2',
                'area_ha',
            ]);
        });
    }
};
