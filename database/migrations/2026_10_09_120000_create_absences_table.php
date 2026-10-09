<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ausencias justificadas: incapacidades del IMSS y permisos. Son un hecho que
 * RH registra, no una solicitud, por eso no llevan estatus ni aprobación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Catálogo en Absence::TYPES. El tipo decide si se pide folio y
            // cómo se cuenta: una incapacidad no es lo mismo que un permiso.
            $table->string('type', 30);

            // Días naturales, como los expide el IMSS; el calendario de
            // asistencia solo marca los que eran laborables.
            $table->date('starts_on');
            $table->date('ends_on');

            $table->string('folio', 40)->nullable();
            $table->date('issued_on')->nullable();
            $table->string('document_url')->nullable();
            $table->string('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'starts_on', 'ends_on']);
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
