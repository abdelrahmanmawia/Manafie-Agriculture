<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcelles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bloc_id')->constrained('blocs')->onDelete('cascade');
            $table->foreignId('sector_id')->constrained('sectors')->onDelete('cascade');
            $table->string('name');
            $table->integer('hass_trees')->default(0);
            $table->integer('fuerte_trees')->default(0);
            $table->integer('lambhass_trees')->default(0);
            $table->integer('zutano_trees')->default(0);
            $table->decimal('area_m2', 12, 2)->default(0);
            $table->decimal('area_ha', 8, 4)->default(0);
            $table->string('spacing')->default('6*3');
            $table->integer('total_trees')->default(0);
            $table->timestamps();

            $table->unique(['sector_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcelles');
    }
};
