<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acceso de los trabajadores a la app móvil. Las sesiones son tokens de
 * Sanctum (personal_access_tokens); worker_devices guarda el teléfono de cada
 * sesión y su token de notificaciones push. Sin borrado lógico: son
 * credenciales y sesiones, y al revocarlas deben desaparecer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('nip');
            // Con fecha, el NIP es temporal: lo generó RH y el trabajador
            // debe cambiarlo en su primer acceso antes de que venza.
            $table->timestamp('nip_expires_at')->nullable();
            $table->foreignId('nip_issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('worker_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_account_id')->constrained()->cascadeOnDelete();
            // Cerrar la sesión borra el teléfono: deja de recibir notificaciones.
            $table->foreignId('personal_access_token_id')->constrained()->cascadeOnDelete();

            // Lo genera la app en su primera ejecución; identifica la
            // instalación sin pedir permisos del sistema.
            $table->string('installation_id', 64)->unique();
            $table->string('platform', 10);
            $table->string('device_name', 100)->nullable();
            $table->string('os_version', 30)->nullable();
            $table->string('app_version', 30)->nullable();

            // Llega después del login, cuando el trabajador acepta los permisos.
            $table->string('expo_push_token')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_devices');
        Schema::dropIfExists('worker_accounts');
    }
};
