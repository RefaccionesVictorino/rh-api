<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integración con el checador ZKTeco (protocolo PUSH/ADMS) y checadas crudas.
 *
 * Portado de la rama feature/adding-checador-logic con la convención de
 * nombres en inglés y dos cambios de diseño: las checadas viven en
 * attendance_punches, que es la única tabla de checadas del sistema (del
 * checador, de la app o capturadas por RH), y el usuario del checador queda
 * ligado al empleado con llave foránea real.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cada terminal física. El serial (SN) es el identificador que el equipo
        // manda en todas sus peticiones, así que es la llave natural.
        Schema::create('time_clock_devices', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number')->unique();
            $table->string('name')->nullable();
            $table->string('location')->nullable();
            $table->string('model')->nullable();
            $table->string('firmware')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('is_active')->default(true);

            // Marcas de agua del protocolo: el equipo pregunta desde qué punto
            // reenviar. Se guardan tal cual las manda el terminal (string).
            $table->string('att_log_stamp')->default('0');
            $table->string('op_log_stamp')->default('0');

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        // Usuarios del checador. Se mantienen aparte de `users` porque son
        // empleados que marcan asistencia, no cuentas que inician sesión. El PIN
        // es global: el mismo usuario checa en cualquier terminal.
        Schema::create('time_clock_users', function (Blueprint $table) {
            $table->id();
            $table->string('pin', 20)->unique();
            $table->string('name', 60);
            $table->string('card_number', 20)->nullable();
            $table->string('password', 8)->nullable();
            $table->unsignedTinyInteger('privilege')->default(0); // 0=usuario, 14=admin

            // Un empleado tiene a lo más un usuario de checador. Nullable: el
            // equipo puede reportar usuarios enrolados en pantalla que RH aún
            // no ha ligado a nadie.
            $table->foreignId('employee_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        // Cola de comandos que el equipo consulta y ejecuta al sondear.
        Schema::create('time_clock_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')
                ->constrained('time_clock_devices')
                ->cascadeOnDelete();

            $table->text('command');                  // cuerpo sin el prefijo "C:<id>:"
            $table->string('type', 30)->nullable();   // etiqueta legible: user_upsert, reboot...
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->integer('result_code')->nullable(); // Return del equipo: >=0 ok, <0 error
            $table->text('response')->nullable();

            $table->timestamps();

            $table->index(['device_id', 'sent_at']);
        });

        // Checadas crudas. Inmutables: nunca se editan ni se borran desde la
        // aplicación. El resumen diario de asistencia se calcula a partir de
        // ellas y puede recalcularse cuantas veces haga falta.
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();

            // Se resuelve desde el PIN al guardar. Nullable: si el equipo manda
            // un PIN que RH todavía no ligó a un empleado, la checada no se
            // pierde; queda pendiente de asociar.
            $table->foreignId('employee_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            // Nulos en checadas manuales o desde la app.
            $table->foreignId('device_id')
                ->nullable()
                ->constrained('time_clock_devices')
                ->nullOnDelete();
            $table->string('pin', 20)->nullable();

            $table->dateTime('punched_at');

            // Códigos del protocolo ZK, usados también para checadas manuales:
            // 0 entrada, 1 salida, 2 salida a comida, 3 regreso de comida,
            // 4 entrada horas extra, 5 salida horas extra.
            $table->unsignedTinyInteger('punch_type')->default(0);
            // 0 contraseña, 1 huella, 2 tarjeta, 15 rostro. Nulo si no aplica.
            $table->unsignedTinyInteger('verify_mode')->nullable();
            $table->unsignedTinyInteger('work_code')->default(0);

            $table->string('source', 10)->default('device'); // device, app, web, manual
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('notes')->nullable();
            $table->text('raw_line')->nullable();          // línea original, para auditar

            $table->timestamps();

            // Clave del diseño: el equipo reenvía lotes cuando no confía en el
            // ACK. Este índice hace que reprocesar el mismo lote sea inofensivo.
            $table->unique(['device_id', 'pin', 'punched_at', 'punch_type'], 'attendance_punches_unique');

            // Asistencia de un empleado en un rango de fechas.
            $table->index(['employee_id', 'punched_at']);
            // Corte diario de toda la empresa.
            $table->index('punched_at');
            $table->index('pin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('time_clock_commands');
        Schema::dropIfExists('time_clock_users');
        Schema::dropIfExists('time_clock_devices');
    }
};
