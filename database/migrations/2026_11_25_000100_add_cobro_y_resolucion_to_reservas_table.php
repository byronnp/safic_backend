<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas: el cobro (cuota de uso y garantía en la cuenta de la unidad), hasta cuándo se paga y quién
 * resolvió una solicitud con aprobación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->foreignId('cuota_uso_id')->nullable()->constrained('cuotas')->nullOnDelete();
            $table->foreignId('cuota_garantia_id')->nullable()->constrained('cuotas')->nullOnDelete();
            $table->timestampTz('limite_pago_en')->nullable();
            $table->foreignId('resuelta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resuelta_en')->nullable();
            $table->string('motivo_rechazo', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cuota_uso_id');
            $table->dropConstrainedForeignId('cuota_garantia_id');
            $table->dropConstrainedForeignId('resuelta_por');
            $table->dropColumn(['limite_pago_en', 'resuelta_en', 'motivo_rechazo']);
        });
    }
};
