<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sub_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')
                ->constrained()
                // Restrict: borrar un área con sub áreas vivas debe fallar en vez
                // de arrastrarlas; el área se da de baja con soft delete.
                ->restrictOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['department_id', 'name']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_departments');
    }
};
