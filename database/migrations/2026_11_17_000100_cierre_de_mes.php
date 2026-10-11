<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre de mes: quién cerró y cuándo, el resumen que quedó al cerrar y, si se reabrió, quién,
 * cuándo y por qué. Un mes cerrado es de solo lectura (ver Services/PeriodoAbierto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periodos_financieros', function (Blueprint $table) {
            $table->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cerrado_en')->nullable();
            $table->jsonb('resumen_cierre')->nullable();
            $table->foreignId('reabierto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reabierto_en')->nullable();
            $table->string('motivo_reapertura', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('periodos_financieros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cerrado_por');
            $table->dropConstrainedForeignId('reabierto_por');
            $table->dropColumn(['cerrado_en', 'resumen_cierre', 'reabierto_en', 'motivo_reapertura']);
        });
    }
};
