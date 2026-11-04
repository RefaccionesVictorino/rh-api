<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asignación de turno a empleado, con vigencia.
 *
 * No se guarda shift_id en employees a propósito: un reporte de marzo debe
 * usar el turno que el empleado tenía en marzo, aunque hoy tenga otro. El
 * turno vigente en una fecha es el renglón con starts_on <= fecha y ends_on
 * nulo o >= fecha. La aplicación garantiza que las vigencias de un mismo
 * empleado nunca se traslapen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')
                ->constrained()
                // La historia de turnos explica la asistencia calculada; no
                // debe desaparecer en silencio con el empleado.
                ->restrictOnDelete();
            $table->foreignId('shift_id')
                ->constrained()
                ->restrictOnDelete();
            $table->date('starts_on');
            // Nulo = vigente hasta nuevo aviso.
            $table->date('ends_on')->nullable();
            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            // Turno vigente de un empleado y su historial.
            $table->index(['employee_id', 'starts_on']);
            // Quiénes están hoy en un turno, y si se puede dar de baja.
            $table->index(['shift_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_shifts');
    }
};
