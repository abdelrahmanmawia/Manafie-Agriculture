<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pointage_records cascaded on employee/operation/bloc delete, so deleting any of those silently
// wiped every pointage row that referenced it — closed quinzaines included, i.e. the raw data
// behind already-issued payslips. harvests.bloc_id did the same to harvest history. Switching to
// restrict makes those deletes fail loudly at the DB level (the controllers catch it and show a
// friendly message — deactivate the employee instead). quinzaine_id keeps its cascade: deleting
// an open quinzaine is meant to discard its draft records, and closed ones can't be deleted.
//
// vehicle_maintenance_logs.created_by also cascaded, so deleting a user deleted every
// maintenance log they ever entered; it now becomes nullable and is nulled out instead.
//
// SQLite can't drop a foreign key (see migration 000041), so the test suite's empty DB just has
// these three tables recreated with the new constraints.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->recreateForSqlite();

            return;
        }

        $this->setDeleteRule('pointage_records', ['employee_id' => 'employees', 'operation_id' => 'operations', 'bloc_id' => 'blocs'], 'restrict');
        $this->setDeleteRule('harvests', ['bloc_id' => 'blocs'], 'restrict');

        Schema::table('vehicle_maintenance_logs', function (Blueprint $table) {
            $table->dropForeign('vehicle_maintenance_logs_created_by_foreign');
        });
        Schema::table('vehicle_maintenance_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Same as 000041: the SQLite test DB only ever migrates forward.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        $this->setDeleteRule('pointage_records', ['employee_id' => 'employees', 'operation_id' => 'operations', 'bloc_id' => 'blocs'], 'cascade');
        $this->setDeleteRule('harvests', ['bloc_id' => 'blocs'], 'cascade');

        Schema::table('vehicle_maintenance_logs', function (Blueprint $table) {
            $table->dropForeign('vehicle_maintenance_logs_created_by_foreign');
        });
        Schema::table('vehicle_maintenance_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    private function setDeleteRule(string $table, array $columns, string $rule): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
            foreach (array_keys($columns) as $column) {
                $blueprint->dropForeign("{$table}_{$column}_foreign");
            }
        });
        Schema::table($table, function (Blueprint $blueprint) use ($columns, $rule) {
            foreach ($columns as $column => $references) {
                $blueprint->foreign($column)->references('id')->on($references)->onDelete($rule);
            }
        });
    }

    private function recreateForSqlite(): void
    {
        Schema::dropIfExists('pointage_records');
        Schema::create('pointage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('quinzaine_id')->constrained()->onDelete('cascade');
            $table->foreignId('operation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('bloc_id')->nullable()->constrained()->restrictOnDelete();
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

            $table->unique(['employee_id', 'quinzaine_id', 'date']);
        });

        Schema::dropIfExists('harvests');
        Schema::create('harvests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('bloc_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sector_id')->nullable();
            $table->unsignedBigInteger('parcelle_id')->nullable();
            $table->date('date');
            $table->string('variety');
            $table->decimal('quantity_kg', 12, 2);
            $table->decimal('estimated_kg', 12, 2)->nullable();
            $table->decimal('actual_kg', 12, 2)->nullable();
            $table->boolean('is_weighed')->default(false);
            $table->timestamp('weighed_at')->nullable();
            $table->string('weighing_batch_id')->nullable();
            $table->integer('boxes_count')->nullable();
            $table->string('grade')->nullable();
            $table->decimal('unit_price_dh', 8, 2)->nullable();
            $table->decimal('total_revenue_dh', 12, 2)->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('vehicle_maintenance_logs');
        Schema::create('vehicle_maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->date('performed_at');
            $table->decimal('cost', 10, 2)->nullable();
            $table->foreignId('performed_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('next_due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
};
