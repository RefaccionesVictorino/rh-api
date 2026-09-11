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
            $table->date('hire_date');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
