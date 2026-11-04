<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El responsable del área es un empleado, y el empleado a su vez pertenece a una
 * sub área: la referencia es circular. Por eso las dos FK se agregan aquí, una
 * vez que las tres tablas ya existen, y no dentro de cada create.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('manager_id')
                ->nullable()
                ->after('description')
                ->constrained('employees')
                // El área sobrevive a la baja de su jefe; queda sin responsable
                // hasta que RH asigne otro.
                ->nullOnDelete();
        });

        Schema::table('sub_departments', function (Blueprint $table) {
            $table->foreignId('manager_id')
                ->nullable()
                ->after('description')
                ->constrained('employees')
                ->nullOnDelete();
        });

        Schema::table('employees', function (Blueprint $table) {
            // Nullable: los 1000 empleados ya cargados no tienen sub área todavía,
            // y un alta puede preceder a su ubicación en el organigrama.
            $table->foreignId('sub_department_id')
                ->nullable()
                ->after('hire_date')
                ->constrained('sub_departments')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['sub_department_id']);
            $table->dropColumn('sub_department_id');
        });

        Schema::table('sub_departments', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn('manager_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn('manager_id');
        });
    }
};
