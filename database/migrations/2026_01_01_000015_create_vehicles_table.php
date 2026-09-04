<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->string('asset_type')->default('vehicle');
            $table->foreignId('default_driver_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('name');
            $table->string('plate_number')->nullable()->unique();
            $table->string('serial_number')->nullable();
            $table->string('type');
            $table->string('model')->nullable();
            $table->string('fuel_type')->default('diesel');
            $table->decimal('capacity_liters', 10, 2)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('operational');
            $table->text('notes')->nullable();
            $table->decimal('default_daily_rate', 10, 2)->nullable();
            $table->boolean('is_location')->default(false);
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_value', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
