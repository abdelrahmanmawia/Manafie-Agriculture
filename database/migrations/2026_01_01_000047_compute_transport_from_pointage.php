<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Transport cost is now computed from the pointage instead of being ticked by hand: a vehicle's
// cost on a day = the number of its riders who worked that day × the vehicle's price per person
// (or its optional flat daily rate), and 0 when none of them worked (TransportService).
//
//  - transport_vehicles.price_per_person: the per-person price agreed for that vehicle, the same
//    for everyone in it (it replaces pricing each rider by their residence).
//  - pointage_records.transport_vehicle_id: the vehicle the worker rode that day, stamped when
//    the record is written (from their équipe), so moving a worker to another équipe later
//    doesn't move their past days to the new vehicle. Existing records are stamped from the
//    worker's current équipe.
//  - transport_vehicle_absences replaces transport_vehicle_attendances: the vehicle is assumed to
//    have come whenever one of its riders worked; the only thing entered by hand now is the
//    exception "the vehicle didn't come that day" (its riders came on their own: they are paid,
//    the vehicle isn't). Old attendance rows ("the vehicle ran") have no meaning in the new
//    model and are dropped.
//  - transport_snapshots: net_per_day becomes the flat daily rate only (null when priced per
//    person); price_per_person and rider_days (sum of riders over the days) are frozen too.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_vehicles', function (Blueprint $table) {
            $table->decimal('price_per_person', 8, 2)->nullable()->after('capacity');
        });

        Schema::table('pointage_records', function (Blueprint $table) {
            $table->foreignId('transport_vehicle_id')->nullable()->after('parcelle_id')
                ->constrained('transport_vehicles')->nullOnDelete();
        });

        foreach (DB::table('equipes')->whereNotNull('transport_vehicle_id')->get(['id', 'transport_vehicle_id']) as $equipe) {
            DB::table('pointage_records')
                ->whereIn('employee_id', DB::table('employees')->where('equipe_id', $equipe->id)->select('id'))
                ->update(['transport_vehicle_id' => $equipe->transport_vehicle_id]);
        }

        Schema::dropIfExists('transport_vehicle_attendances');
        Schema::create('transport_vehicle_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamps();

            $table->unique(['transport_vehicle_id', 'date']);
        });

        Schema::table('transport_snapshots', function (Blueprint $table) {
            $table->decimal('net_per_day', 8, 2)->nullable()->change();
            $table->decimal('price_per_person', 8, 2)->nullable()->after('net_per_day');
            $table->unsignedInteger('rider_days')->default(0)->after('days_count');
        });
    }

    public function down(): void
    {
        // Same as 000041/000046: the SQLite test DB only ever migrates forward.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('transport_snapshots', function (Blueprint $table) {
            $table->dropColumn(['price_per_person', 'rider_days']);
        });
        DB::table('transport_snapshots')->whereNull('net_per_day')->update(['net_per_day' => 0]);
        Schema::table('transport_snapshots', function (Blueprint $table) {
            $table->decimal('net_per_day', 8, 2)->nullable(false)->change();
        });

        Schema::dropIfExists('transport_vehicle_absences');
        Schema::create('transport_vehicle_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamps();

            $table->unique(['transport_vehicle_id', 'date']);
        });

        Schema::table('pointage_records', function (Blueprint $table) {
            $table->dropForeign(['transport_vehicle_id']);
            $table->dropColumn('transport_vehicle_id');
        });

        Schema::table('transport_vehicles', function (Blueprint $table) {
            $table->dropColumn('price_per_person');
        });
    }
};
