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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('last_name');
            $table->string('second_last_name')->nullable();
            $table->string('gender');
            $table->string('rfc');
            $table->string('curp');
            $table->string('nss');
            $table->string('birth_country');
            $table->string('marital_status');
            $table->date('birthdate');
            $table->string('work_phone');
            $table->string('personal_phone');
            $table->string('personal_email');
            $table->string('address');
            $table->string('municipality');
            $table->string('postal_code');
            $table->string('photo_url')->nullable();
            $table->date('hire_date');
            $table->softDeletes();
            $table->timestamps();
        });

        // Va aquí y no en create_users_table: esa corre antes de que exista
        // employees. Nullable porque el administrador puede no ser trabajador;
        // sin unique por la misma razón que el correo del usuario.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')
                ->nullable()
                ->after('id')
                ->constrained('employees')
                // Restrict y no null: desvincular en silencio dejaría al usuario
                // sin trabajador, es decir, sin recorte de alcance.
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
        });

        Schema::dropIfExists('employees');
    }
};
