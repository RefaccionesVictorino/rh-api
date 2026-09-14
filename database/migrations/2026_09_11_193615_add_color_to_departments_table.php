<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Color de identificación del área. Las sub áreas no guardan color: el frontend
 * deriva su tono del área, de modo que renombrar o recolorear un área arrastra
 * a toda su rama del organigrama sin tocar más registros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // 7 caracteres: #RRGGBB en minúsculas, normalizado en el FormRequest.
            $table->string('color', 7)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
