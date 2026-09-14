<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de turnos.
 *
 * Un turno es una plantilla de horario: se define una vez y se asigna a los
 * empleados con vigencia (tabla employee_shifts, siguiente módulo). El horario
 * se guarda por día de la semana, para que un mismo turno pueda tener sábado
 * de medio día o descanso entre semana sin crear turnos distintos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->string('description')->nullable();

            // Minutos después de la hora de entrada a partir de los cuales la
            // llegada cuenta como retardo. Vive en el turno y no en una
            // configuración global porque no es igual para mostrador que para
            // almacén nocturno.
            $table->unsignedSmallInteger('tolerance_minutes')->default(10);

            // Minutos después de la hora de entrada a partir de los cuales la
            // llegada cuenta como falta. Nulo: un retardo nunca se vuelve falta
            // por sí solo.
            $table->unsignedSmallInteger('absence_after_minutes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index('name');
            $table->index('is_active');
        });

        Schema::create('shift_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')
                ->constrained()
                // Los días no existen sin su turno. El turno se da de baja con
                // soft delete, así que el cascade solo aplica a un borrado
                // físico deliberado.
                ->cascadeOnDelete();

            // 0 = domingo … 6 = sábado, igual que Carbon::dayOfWeek. Siempre se
            // guardan los 7 renglones de cada turno: el cálculo de asistencia
            // busca el día por (shift_id, weekday) sin tener que suponer nada
            // cuando no existe.
            $table->unsignedTinyInteger('weekday');
            $table->boolean('is_rest_day')->default(false);

            // Nulos cuando es descanso. Si end_time <= start_time el turno cruza
            // medianoche y la salida cae en el día siguiente.
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            // Horario de comida. Ambos nulos = turno corrido. Se guarda como
            // ventana y no como minutos porque el checador reporta salida y
            // entrada de descanso, y así se puede comparar contra lo esperado.
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();

            $table->timestamps();

            $table->unique(['shift_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_days');
        Schema::dropIfExists('shifts');
    }
};
