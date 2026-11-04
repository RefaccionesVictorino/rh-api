<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contactos de emergencia del trabajador. Se capturan junto con el expediente
 * y se reemplazan completos en cada edición, así que no llevan historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('relationship', 50);
            // Solo dígitos, sin "+". Va aparte del número para armar el enlace
            // de WhatsApp sin adivinar dónde termina la lada.
            $table->string('phone_country_code', 4)->default('52');
            $table->string('phone', 20);
            $table->boolean('has_whatsapp')->default(false);
            $table->string('email', 150)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_emergency_contacts');
    }
};
