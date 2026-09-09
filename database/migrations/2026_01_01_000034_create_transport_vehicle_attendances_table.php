<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_vehicle_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quinzaine_id')->constrained()->cascadeOnDelete();
            // A row's mere existence means "this vehicle operated this day" — same convention
            // as the rest of this feature (see transport_snapshots' comments), and mirrors how
            // PointageController::updateCell() deletes a record for "no work" rather than
            // storing a false flag.
            $table->date('date');
            $table->timestamps();

            $table->unique(['transport_vehicle_id', 'quinzaine_id', 'date'], 'tva_vehicle_quinzaine_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_vehicle_attendances');
    }
};
