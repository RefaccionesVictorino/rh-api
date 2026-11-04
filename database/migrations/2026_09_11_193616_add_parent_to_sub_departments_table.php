<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anidamiento de sub áreas: una sub área puede colgar de otra, a cualquier
 * profundidad.
 *
 * `department_id` se conserva en todos los niveles, no solo en la raíz: aunque
 * sea derivable subiendo por los padres, tenerlo a mano permite filtrar y
 * colorear por área con una sola condición, sin recorrer la cadena. El API se
 * encarga de que una sub área hija siempre herede el área de su padre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_departments', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('department_id')
                ->constrained('sub_departments')
                // Restrict: igual que un área con sub áreas, borrar una sub área
                // con hijas debe fallar en vez de arrastrarlas.
                ->restrictOnDelete();

            // El organigrama pide las hijas de un nodo a la vez; este índice es
            // el que sirve esa consulta.
            $table->index(['parent_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('sub_departments', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'name']);
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
