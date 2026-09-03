<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_exit_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            // Stored verbatim into manual_stock_entries.entry_type — stays stable even if a
            // farm_manager/super_admin later edits the display label. The five defaults below
            // use the same keys this app has always stored, so existing rows and the
            // 'maintenance' special-case in the sortie form keep working unmodified.
            $table->string('key');
            $table->string('label');
            // 'Maintenance' is the only default type that shows the "Intervention liée" field —
            // driven by this flag instead of a hardcoded key check, so a farm_manager could add
            // another maintenance-like type later without a code change.
            $table->boolean('requires_maintenance_log')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['farm_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_exit_types');
    }
};
