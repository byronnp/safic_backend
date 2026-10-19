<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas: la devolución de la garantía. El sistema no mueve ese dinero (sale de caja o del banco y se concilia a mano como
 * «otro»): aquí solo queda el registro de que se devolvió, cuánto, cómo y cuándo, para no devolver dos veces ni perder el rastro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->decimal('garantia_devuelta_monto', 10, 2)->nullable();
            $table->date('garantia_devuelta_en')->nullable();                      // día en que se entregó el dinero
            $table->string('garantia_devuelta_forma', 14)->nullable();              // efectivo, transferencia
            $table->string('garantia_devuelta_referencia', 40)->nullable();         // n.° de transferencia o recibo
            $table->foreignId('garantia_devuelta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('garantia_devuelta_registrada_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('garantia_devuelta_por');
            $table->dropColumn(['garantia_devuelta_monto', 'garantia_devuelta_en', 'garantia_devuelta_forma', 'garantia_devuelta_referencia', 'garantia_devuelta_registrada_en']);
        });
    }
};
