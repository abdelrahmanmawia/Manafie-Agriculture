<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocs', function (Blueprint $table) {
            $table->id();
            // No FK constraint in the live database — blocs moved from enterprise-scoped to
            // farm-scoped, and the replacement farm_id column never registered a real constraint.
            $table->unsignedBigInteger('farm_id')->nullable();
            $table->string('name');
            $table->decimal('area_m2', 12, 2)->default(0);
            $table->decimal('area_ha', 8, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocs');
    }
};
