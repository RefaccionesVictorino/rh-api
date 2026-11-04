<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La sucursal es del lugar donde se marcó la checada, no del empleado: un
 * trabajador puede checar en cualquiera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('municipality')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index('name');
            $table->index('is_active');
        });

        Schema::table('time_clock_devices', function (Blueprint $table) {
            // Nullable: el auto registro da de alta terminales sin sucursal.
            $table->foreignId('location_id')
                ->nullable()
                ->after('serial_number')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('device_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['location_id', 'punched_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id', 'punched_at']);
            $table->dropColumn('location_id');
        });

        Schema::table('time_clock_devices', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::dropIfExists('locations');
    }
};
