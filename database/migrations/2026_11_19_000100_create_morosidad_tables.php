<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Morosidad. Las reglas viven junto a las demás reglas de dinero (`configuraciones_finanzas`).
 * `convenios_pago` son acuerdos de pagar la deuda vencida en cuotas; `restricciones_mora` el
 * proceso del Decreto 462 (se notifica con el detalle, aplica a los N días y se levanta al
 * pagar o firmar un convenio); `recordatorios_mora` lo ya avisado, para no repetir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_finanzas', function (Blueprint $table) {
            $table->unsignedSmallInteger('dias_gracia')->default(5);
            $table->unsignedSmallInteger('meses_restriccion')->default(3);
            $table->unsignedSmallInteger('dias_aviso_restriccion')->default(5);
        });

        Schema::create('convenios_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->decimal('monto_total', 12, 2);                        // la deuda vencida al firmar
            $table->unsignedSmallInteger('cuotas');
            $table->date('desde');                                        // los pagos aprobados desde aquí cuentan al convenio
            $table->date('primera_cuota_el');
            $table->string('estado', 12)->default('activo');              // activo, cumplido, cancelado
            $table->string('motivo_cancelacion', 300)->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['condominio_id', 'unidad_id']);
        });
        // Un solo convenio activo por unidad
        DB::statement("CREATE UNIQUE INDEX convenios_activo_unico ON convenios_pago (unidad_id) WHERE estado = 'activo'");
        DB::statement('ALTER TABLE convenios_pago ADD CONSTRAINT convenios_cuotas_validas CHECK (cuotas BETWEEN 2 AND 36 AND monto_total > 0)');

        Schema::create('restricciones_mora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->string('estado', 12);                                 // notificada, activa, levantada
            $table->timestamp('notificada_en');
            $table->date('restringe_desde');
            $table->decimal('deuda_al_notificar', 12, 2);
            $table->unsignedSmallInteger('meses_al_notificar');
            $table->timestamp('levantada_en')->nullable();
            $table->string('motivo_levantamiento', 300)->nullable();      // pago, convenio, o el motivo escrito
            $table->foreignId('levantada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['condominio_id', 'estado']);
        });
        DB::statement("CREATE UNIQUE INDEX restricciones_abierta_unica ON restricciones_mora (unidad_id) WHERE estado IN ('notificada', 'activa')");

        Schema::create('recordatorios_mora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->string('tipo', 10);                                   // antes, dia, atraso, manual
            $table->date('referencia');                                   // vencimiento o fecha del aviso: evita avisar dos veces lo mismo
            $table->string('enviado_a', 120)->nullable();
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['condominio_id', 'unidad_id']);
        });
        DB::statement("CREATE UNIQUE INDEX recordatorios_automaticos_unicos ON recordatorios_mora (unidad_id, tipo, referencia) WHERE tipo <> 'manual'");

        foreach (['convenios_pago', 'restricciones_mora', 'recordatorios_mora'] as $tabla) {
            RowLevelSecurity::enable($tabla);
        }
    }

    public function down(): void
    {
        foreach (['recordatorios_mora', 'restricciones_mora', 'convenios_pago'] as $tabla) {
            RowLevelSecurity::disable($tabla);
            Schema::dropIfExists($tabla);
        }
        Schema::table('configuraciones_finanzas', function (Blueprint $table) {
            $table->dropColumn(['dias_gracia', 'meses_restriccion', 'dias_aviso_restriccion']);
        });
    }
};
