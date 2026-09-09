<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A vehicle's operating-day is a farm-wide fact, not a division fact — every enterprise of a
// farm shares the same pooled vehicles, so keying attendance to one specific division's
// Quinzaine let the SAME real-world day get marked once per division and be summed that many
// times over in TransportService/Résumé Transport. No real attendance data exists yet (nothing
// has been closed for real), so this drops and recreates the table (same approach the
// transport_snapshots restructure used) rather than altering it — SQLite (the test suite's
// engine) can't drop a foreign key/composite index via ALTER TABLE at all, only MySQL can.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('transport_vehicle_attendances');

        Schema::create('transport_vehicle_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_vehicle_id')->constrained()->cascadeOnDelete();
            // A row's mere existence means "this vehicle operated this day" — same convention
            // as the rest of this feature.
            $table->date('date');
            $table->timestamps();

            $table->unique(['transport_vehicle_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_vehicle_attendances');
    }
};
