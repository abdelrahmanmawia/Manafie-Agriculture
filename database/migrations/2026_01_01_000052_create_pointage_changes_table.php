<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The history of every pointage day: who created, changed or removed it, when, and what it was
 * before and after (PointageRecordObserver). It outlives the record — no foreign key to it — so a
 * deleted day keeps its trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pointage_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pointage_record_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('quinzaine_id')->index();
            $table->date('date');
            $table->string('nature', 20)->default('jour');
            $table->string('action', 10); // created | updated | deleted
            $table->json('avant')->nullable();
            $table->json('apres')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['employee_id', 'quinzaine_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pointage_changes');
    }
};
