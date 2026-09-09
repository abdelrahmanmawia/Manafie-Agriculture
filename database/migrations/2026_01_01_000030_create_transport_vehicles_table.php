<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete, not cascade: a company with vehicles still assigned to employees
            // or referenced by frozen transport_snapshots must not be deletable out from under them.
            $table->foreignId('transport_company_id')->constrained('transport_companies')->restrictOnDelete();
            // The matricule/code — one row per physical van, not per employee.
            $table->string('code');
            $table->string('driver_name')->nullable();
            $table->string('driver_phone')->nullable();
            // Seats — not in the source Excel, but cheap to add and useful to flag an
            // over-assigned vehicle; purely informational, nothing currently enforces it.
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['farm_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_vehicles');
    }
};
