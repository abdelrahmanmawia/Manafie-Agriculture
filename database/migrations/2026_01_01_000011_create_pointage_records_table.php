<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pointage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->foreignId('quinzaine_id')->constrained()->onDelete('cascade');
            // Nullable: a quinzaine can be closed with unassigned days, and unworked-JF synthetic
            // records have no operation/bloc at all.
            $table->foreignId('operation_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('bloc_id')->nullable()->constrained()->onDelete('cascade');
            // No FK constraint on parcelle_id/sector_id in the live database (added later via
            // ALTER, which never registered a real constraint here) — kept as plain columns.
            $table->unsignedBigInteger('parcelle_id')->nullable();
            $table->unsignedBigInteger('sector_id')->nullable();
            $table->date('date');
            $table->decimal('hours', 8, 2);
            $table->decimal('rate', 10, 2);
            $table->decimal('brut', 10, 2);
            $table->decimal('cnss', 10, 2)->default(0);
            $table->decimal('amo', 10, 2)->default(0);
            $table->decimal('ir', 10, 2)->default(0);
            $table->decimal('net', 10, 2);
            $table->boolean('is_jf')->default(false);
            $table->decimal('quantity', 10, 2)->nullable();
            $table->string('scan_uuid')->nullable();
            $table->timestamps();

            // Guards against a real duplicate-row bug: PointageController used to save a cell by
            // deleting the existing row then inserting a new one in two separate statements: a
            // double submission (e.g. a double-click on "Enregistrer") could interleave between
            // them and each end up inserting its own row for the same day. Confirmed happening to
            // two employees in the 2QZ Août 2026 import, silently doubling their counted net for
            // that date. The write path now upserts through this constraint (see
            // PointageController::upsertPointageRecord()) instead of relying on app logic alone.
            $table->unique(['employee_id', 'quinzaine_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pointage_records');
    }
};
