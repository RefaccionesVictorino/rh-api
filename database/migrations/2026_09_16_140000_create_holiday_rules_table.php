<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de festivos que se repiten cada año.
 *
 * La regla guarda cómo se calcula el día, no la fecha, de modo que no hay que
 * capturar nada al cambiar de año. La tabla `holidays` queda para las fechas
 * concretas de la empresa (inventario, aniversario) y para la excepción de un
 * año puntual sobre una regla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // fixed: día y mes fijos. nth_weekday: n-ésimo día de la semana del
            // mes, como los feriados movibles del artículo 74.
            $table->string('rule_type', 20)->default('fixed');
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day')->nullable();
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('week_of_month')->nullable();

            $table->string('observance', 20)->default('rest');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();

            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);

            // Una regla agregada a media historia no debe aparecer en años
            // anteriores a su acuerdo.
            $table->year('starts_year')->nullable();
            $table->year('ends_year')->nullable();

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::table('holidays', function (Blueprint $table) {
            // La fecha concreta que pisa a la regla ese año; nulo en los días
            // propios de la empresa.
            $table->foreignId('holiday_rule_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropForeign(['holiday_rule_id']);
            $table->dropColumn('holiday_rule_id');
        });

        Schema::dropIfExists('holiday_rules');
    }
};
