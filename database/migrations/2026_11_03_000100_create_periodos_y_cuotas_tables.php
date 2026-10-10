<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuotas por cobrar. `periodos_financieros` es el mes que el condominio emite (y que
 * después cierra la conciliación); `cuotas` es lo que debe cada unidad en ese mes.
 * El dinero va en numeric(12,2): nunca float. Una unidad tiene una sola cuota ordinaria
 * por mes, así emitir dos veces el mismo mes no duplica nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodos_financieros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->date('periodo');                          // siempre el día 1 del mes
            $table->string('estado', 10)->default('abierto'); // abierto, cerrado
            $table->timestamp('emitido_en')->nullable();
            $table->timestamps();
            $table->unique(['condominio_id', 'periodo']);
        });

        Schema::create('cuotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->date('periodo');                          // día 1 del mes que se cobra
            $table->string('concepto', 20)->default('ordinaria'); // ordinaria, extraordinaria
            $table->decimal('monto', 12, 2);
            $table->decimal('pagado', 12, 2)->default(0);
            $table->date('vence_el');
            $table->timestamps();

            $table->unique(['condominio_id', 'unidad_id', 'periodo', 'concepto']);
            $table->index(['condominio_id', 'periodo']);
            $table->index(['condominio_id', 'vence_el']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cuotas ADD CONSTRAINT cuotas_montos_validos CHECK (monto > 0 AND pagado >= 0 AND pagado <= monto)');
        }

        RowLevelSecurity::enable('periodos_financieros');
        RowLevelSecurity::enable('cuotas');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('cuotas');
        RowLevelSecurity::disable('periodos_financieros');
        Schema::dropIfExists('cuotas');
        Schema::dropIfExists('periodos_financieros');
    }
};
