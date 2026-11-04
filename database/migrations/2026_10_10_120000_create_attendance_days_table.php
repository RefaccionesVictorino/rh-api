<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumen diario de asistencia por empleado y fecha.
 *
 * Es un derivado: lo produce AttendanceCalendarService a partir de las
 * checadas crudas, el turno vigente, las excepciones, vacaciones y ausencias.
 * Se guarda para que los reportes de toda la empresa y las notificaciones de
 * faltas se resuelvan en SQL, y para que un cambio posterior en el turno no
 * reescriba lo que ya se evaluó. Solo existen renglones de días ya cerrados
 * (anteriores a hoy); el día en curso se calcula al vuelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            // Catálogo en AttendanceCalendarService::STATUS_*.
            $table->string('status', 20);
            $table->string('schedule_source', 10)->nullable();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('absence_id')->nullable()->constrained()->nullOnDelete();

            // Horario contra el que se evaluó, para explicar el renglón aunque
            // el turno cambie después.
            $table->string('expected_start', 5)->nullable();
            $table->string('expected_end', 5)->nullable();
            $table->unsignedSmallInteger('expected_work_minutes')->default(0);
            $table->unsignedSmallInteger('expected_break_minutes')->default(0);

            $table->string('first_in', 5)->nullable();
            $table->string('last_out', 5)->nullable();
            $table->string('break_out', 5)->nullable();
            $table->string('break_in', 5)->nullable();

            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('worked_minutes')->default(0);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('break_overrun_minutes')->default(0);
            $table->boolean('break_exceeded')->default(false);
            $table->boolean('missing_out')->default(false);
            $table->boolean('irregular_punches')->default(false);

            // Decisión de RH sobre el día. El recálculo pisa las columnas
            // calculadas y conserva estas.
            $table->string('justification')->nullable();
            $table->foreignId('justified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('justified_at')->nullable();

            // Cierre de nómina: un renglón bloqueado ya no se recalcula.
            $table->timestamp('locked_at')->nullable();

            $table->timestamp('evaluated_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id', 'date']);
            // Incidencias de toda la empresa en un rango.
            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_days');
    }
};
