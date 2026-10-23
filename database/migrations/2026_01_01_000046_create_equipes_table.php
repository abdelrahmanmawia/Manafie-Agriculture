<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The first pointage step on the farm is done per équipe (a chef d'équipe's sheet), not per
// vehicle: two équipes can ride the same bus, and some équipes ride none. An équipe has a
// chef (one of its members) and optionally the vehicle it rides — and, as agreed with the
// business, that vehicle IS how its members travel: a vehicle's riders (whose residences price
// its "auto" daily cost, see TransportVehicle::netPerDay()) are now the members of the équipes
// riding it. So employees.transport_vehicle_id is replaced by employees.equipe_id rather than
// kept alongside it (two assignments that could disagree).
//
// Existing riders are carried over: each vehicle that has riders becomes one équipe named after
// the vehicle, riding it, with those riders as members (no chef yet — set it on the Équipes
// page).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('chef_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('transport_vehicle_id')->nullable()->constrained('transport_vehicles')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['farm_id', 'name']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('equipe_id')->nullable()->after('transport_vehicle_id')->constrained('equipes')->nullOnDelete();
        });

        $vehicles = DB::table('transport_vehicles')
            ->whereIn('id', DB::table('employees')->whereNotNull('transport_vehicle_id')->select('transport_vehicle_id'))
            ->get(['id', 'farm_id', 'code']);

        foreach ($vehicles as $vehicle) {
            $equipeId = DB::table('equipes')->insertGetId([
                'farm_id' => $vehicle->farm_id,
                'name' => $vehicle->code,
                'transport_vehicle_id' => $vehicle->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('employees')->where('transport_vehicle_id', $vehicle->id)->update(['equipe_id' => $equipeId]);
        }

        // SQLite (test suite only, always an empty fresh DB) can't drop a column that carries a
        // foreign key; nothing reads it any more, so it's simply left there.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropForeign(['transport_vehicle_id']);
                $table->dropColumn('transport_vehicle_id');
            });
        }
    }

    public function down(): void
    {
        // Same as 000041: the SQLite test DB only ever migrates forward (and can't drop FKs).
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('transport_vehicle_id')->nullable()->after('residence_location_id')
                ->constrained('transport_vehicles')->nullOnDelete();
        });

        // Members ride their équipe's vehicle.
        foreach (DB::table('equipes')->whereNotNull('transport_vehicle_id')->get(['id', 'transport_vehicle_id']) as $equipe) {
            DB::table('employees')->where('equipe_id', $equipe->id)->update(['transport_vehicle_id' => $equipe->transport_vehicle_id]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['equipe_id']);
            $table->dropColumn('equipe_id');
        });
        Schema::dropIfExists('equipes');
    }
};
