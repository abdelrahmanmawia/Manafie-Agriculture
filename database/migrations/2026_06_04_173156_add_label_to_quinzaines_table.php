<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quinzaines', function (Blueprint $table) {
            $table->string('label')->nullable()->after('enterprise_id');
        });
    }

    public function down(): void
    {
        Schema::table('quinzaines', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
