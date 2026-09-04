<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Cascades so deleting a farm removes the farm_manager/data_entry accounts scoped to
            // it (an orphaned account pointing at a farm_id that no longer exists could never be
            // scoped correctly again). super_admin has no farm_id of its own, so it's unaffected.
            $table->foreignId('farm_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            // enterprise_admin was a 4th, never-actually-checked role — every authorization
            // helper across the app only ever tested for 'data_entry' and let anything else
            // through, so it silently inherited farm_manager-level power app-wide despite the
            // UI framing it as division-scoped ("Admin Division"). Removed rather than wired
            // up properly, since nothing in the app was actually designed around a 4th tier.
            // data_entry is the safe default for a role column no write path should ever skip.
            $table->string('role')->default('data_entry');
            // Only meaningful for role='data_entry' — see User::canAccessPointage()/
            // canAccessStock(). A magasinier gets can_access_stock only, a pointeur gets
            // can_access_pointage only, someone doing both gets both. Default true so seeders/
            // factories that don't set these explicitly keep the pre-existing "data_entry sees
            // everything" behavior; the actual user-creation form defaults its own state to
            // both unchecked instead, so a real new account fails closed until someone
            // deliberately grants it a domain.
            $table->boolean('can_access_pointage')->default(true);
            $table->boolean('can_access_stock')->default(true);
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
