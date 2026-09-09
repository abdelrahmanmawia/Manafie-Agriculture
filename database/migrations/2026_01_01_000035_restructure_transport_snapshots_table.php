<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Drops and recreates transport_snapshots rather than altering it: the table has never been
// populated by a real quinzaine close (only by rolled-back verification transactions during
// this feature's own development), so there's no historical data to preserve. The shape
// changes from "one row per vehicle+location combo, priced per rider" to "one row per vehicle,
// priced per day it operated" (see TransportVehicle::netPerDay() and TransportService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('transport_snapshots');

        Schema::create('transport_snapshots', function (Blueprint $table) {
            $table->id();
            // One row per (quinzaine, vehicle) — not per vehicle+location anymore, since a
            // vehicle's net/day already aggregates all its riders' locations internally.
            $table->foreignId('quinzaine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_vehicle_id')->nullable()->constrained('transport_vehicles')->nullOnDelete();
            $table->foreignId('transport_company_id')->nullable()->constrained('transport_companies')->nullOnDelete();
            // Denormalized at freeze time — history must keep reading correctly even if the
            // vehicle/company is later renamed or deleted, same reasoning an invoice doesn't
            // change retroactively when a price list changes.
            $table->string('vehicle_code')->nullable();
            $table->string('company_name')->nullable();
            // Frozen daily cost at close time — whatever TransportVehicle::netPerDay() (auto or
            // fixed_net_per_day) resolved to at that instant, same freeze principle as before.
            $table->decimal('net_per_day', 8, 2);
            $table->unsignedInteger('days_count');
            $table->decimal('subtotal', 10, 2);
            // Informational only (current rider count at freeze time) — not part of the money
            // math, which is entirely net_per_day * days_count now.
            $table->unsignedInteger('employee_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_snapshots');
    }
};
