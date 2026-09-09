<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Fixed per-person price for this residence town, set by distance from the farm —
            // not tunable per employee, only per location (see TransportService).
            $table->decimal('price_per_person', 8, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['farm_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_locations');
    }
};
