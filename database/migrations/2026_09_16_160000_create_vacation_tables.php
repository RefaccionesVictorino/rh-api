<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vacaciones: tabulador de la ley, periodo anual por empleado y solicitudes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tabulador del artículo 76. Configurable y no en código porque la
        // reforma de 2023 ya cambió los días y puede volver a cambiar.
        Schema::create('vacation_entitlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('from_year');

            // Nulo: último renglón abierto, para los años siguientes.
            $table->unsignedSmallInteger('to_year')->nullable();
            $table->unsignedSmallInteger('days');
            $table->timestamps();

            $table->unique('from_year');
        });

        // Un periodo por aniversario de ingreso. Saldo = entitled + adjustment - taken.
        Schema::create('vacation_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year_number');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('expires_on');

            $table->unsignedSmallInteger('entitled_days');
            $table->smallInteger('adjustment_days')->default(0);
            $table->decimal('taken_days', 5, 1)->default(0);
            $table->string('adjustment_reason')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'year_number']);
            $table->index(['employee_id', 'expires_on']);
        });

        Schema::create('vacation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');

            // Días hábiles según el turno del empleado; medio día es válido.
            $table->decimal('requested_days', 5, 1);

            $table->string('status', 20)->default('pending');
            $table->text('comments')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['starts_on', 'ends_on']);
        });

        // Un renglón por día consumido: el calendario sabe qué marcar y una
        // solicitud a caballo entre dos periodos carga cada día al suyo.
        Schema::create('vacation_request_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacation_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vacation_period_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('days', 3, 1)->default(1);
            $table->timestamps();

            $table->unique(['vacation_request_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacation_request_days');
        Schema::dropIfExists('vacation_requests');
        Schema::dropIfExists('vacation_periods');
        Schema::dropIfExists('vacation_entitlements');
    }
};
