<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_snapshots', function (Blueprint $table) {
            $table->id();
            // Multiple rows per quinzaine (one per vehicle+location combo), unlike
            // quinzaine_summaries' single-row-per-quinzaine shape — this is a breakdown table.
            $table->foreignId('quinzaine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_vehicle_id')->nullable()->constrained('transport_vehicles')->nullOnDelete();
            $table->foreignId('transport_company_id')->nullable()->constrained('transport_companies')->nullOnDelete();
            $table->foreignId('transport_location_id')->nullable()->constrained('transport_locations')->nullOnDelete();
            // Denormalized at freeze time — history must keep reading correctly even if the
            // vehicle/company/location is later renamed or deleted, same reasoning an invoice
            // doesn't change retroactively when a price list changes.
            $table->string('vehicle_code')->nullable();
            $table->string('company_name')->nullable();
            $table->string('location_name');
            $table->unsignedInteger('employee_count');
            $table->decimal('price_per_person', 8, 2);
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_snapshots');
    }
};
