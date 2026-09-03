<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            // Stored verbatim into vehicles.type — stays stable even if a farm_manager/
            // super_admin later edits the display label. The six defaults below use the same
            // keys this app has always stored, so existing vehicle rows keep working
            // unmodified.
            $table->string('key');
            $table->string('label');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['farm_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_types');
    }
};
