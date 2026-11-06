<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borrado lógico en las tablas del sistema que aún no lo tenían. Las tablas
 * propias del framework y de los paquetes (caché, colas, sesiones, permisos,
 * tokens) se dejan fuera: sus borrados deben ser físicos.
 */
return new class extends Migration
{
    private const TABLES = [
        'shift_days',
        'employee_shifts',
        'time_clock_devices',
        'time_clock_users',
        'time_clock_commands',
        'attendance_punches',
        'holiday_rules',
        'holidays',
        'schedule_overrides',
        'vacation_entitlements',
        'vacation_periods',
        'vacation_requests',
        'vacation_request_days',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
