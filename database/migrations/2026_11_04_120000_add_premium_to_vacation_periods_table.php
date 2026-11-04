<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prima vacacional del periodo (artículo 80). Se paga una sola vez por
 * periodo; "pagada" es que tenga fecha. El porcentaje se copia del global
 * vigente al registrar el pago, para que un cambio posterior no reescriba
 * lo que ya se pagó. El monto lo captura RH: el sistema no conoce el sueldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacation_periods', function (Blueprint $table) {
            $table->decimal('premium_percentage', 5, 2)->nullable()->after('adjustment_reason');
            $table->decimal('premium_amount', 10, 2)->nullable()->after('premium_percentage');
            $table->date('premium_paid_on')->nullable()->after('premium_amount');
            $table->foreignId('premium_paid_by')->nullable()->after('premium_paid_on')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vacation_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('premium_paid_by');
            $table->dropColumn(['premium_percentage', 'premium_amount', 'premium_paid_on']);
        });
    }
};
