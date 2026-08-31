<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bloc_id')->constrained('blocs')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('area_m2', 12, 2)->default(0);
            $table->decimal('area_ha', 8, 4)->default(0);
            $table->integer('total_trees')->default(0);
            $table->string('spacing')->nullable();
            $table->timestamps();

            $table->unique(['bloc_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sectors');
    }
};
