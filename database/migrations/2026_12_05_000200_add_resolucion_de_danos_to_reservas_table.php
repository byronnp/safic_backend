<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas: lo que decidió la administración sobre los daños reportados al recibir el área. Solo se registra la decisión; el
 * único movimiento de dinero que crea el sistema es el cobro a la cuenta de la unidad (cuota extraordinaria). Devolver o
 * descontar la garantía queda como «por devolver» y se hace aparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->string('danos_decision', 20)->nullable();           // sin_cargo, descontar_garantia, cobrar_cuenta
            $table->decimal('danos_monto', 10, 2)->nullable();
            $table->foreignId('danos_cuota_id')->nullable()->constrained('cuotas')->nullOnDelete();
            $table->string('danos_nota', 300)->nullable();
            $table->foreignId('danos_resuelta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('danos_resuelta_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('danos_cuota_id');
            $table->dropConstrainedForeignId('danos_resuelta_por');
            $table->dropColumn(['danos_decision', 'danos_monto', 'danos_nota', 'danos_resuelta_en']);
        });
    }
};
