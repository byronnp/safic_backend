<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Caja chica: un fondo fijo a cargo de una persona, con sus gastos menores respaldados con foto.
 * Al reponer, los movimientos abiertos se agrupan en un gasto (cuenta por pagar) que sigue el flujo
 * normal: aprobación, pago por el tesorero y conciliación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas_chicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->unique()->constrained('condominios')->cascadeOnDelete();
            $table->decimal('fondo', 12, 2);
            $table->string('responsable_nombre', 80);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE cajas_chicas ADD CONSTRAINT cajas_chicas_fondo_positivo CHECK (fondo > 0)');

        Schema::create('caja_chica_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('concepto', 120);
            $table->string('comercio', 80)->nullable();
            $table->decimal('monto', 12, 2);
            $table->string('respaldo_ruta', 255);                          // la foto es obligatoria
            $table->string('estado', 10)->default('abierto');              // abierto, repuesto
            $table->foreignId('gasto_id')->nullable()->constrained('gastos')->nullOnDelete();   // la reposición
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['condominio_id', 'estado']);
        });
        DB::statement('ALTER TABLE caja_chica_movimientos ADD CONSTRAINT caja_chica_monto_positivo CHECK (monto > 0)');

        RowLevelSecurity::enable('cajas_chicas');
        RowLevelSecurity::enable('caja_chica_movimientos');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('caja_chica_movimientos');
        RowLevelSecurity::disable('cajas_chicas');
        Schema::dropIfExists('caja_chica_movimientos');
        Schema::dropIfExists('cajas_chicas');
    }
};
