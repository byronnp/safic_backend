<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuotas extraordinarias (varias por mes, cada una con su detalle) y pagos en efectivo que registra
 * la administración. Una unidad sigue teniendo una sola cuota ordinaria por mes; las
 * extraordinarias se distinguen por su detalle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuotas', function (Blueprint $table) {
            $table->string('detalle', 80)->nullable();
            $table->dropUnique(['condominio_id', 'unidad_id', 'periodo', 'concepto']);
        });
        DB::statement("CREATE UNIQUE INDEX cuotas_ordinaria_unica ON cuotas (condominio_id, unidad_id, periodo) WHERE concepto = 'ordinaria'");
        DB::statement("CREATE UNIQUE INDEX cuotas_extraordinaria_unica ON cuotas (condominio_id, unidad_id, periodo, lower(detalle)) WHERE concepto = 'extraordinaria'");

        Schema::table('pagos', function (Blueprint $table) {
            // Quién pagó, cuando no hay una cuenta detrás (efectivo): el responsable de pago de la unidad
            $table->string('pagador_nombre', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pagos', fn (Blueprint $t) => $t->dropColumn('pagador_nombre'));
        DB::statement('DROP INDEX IF EXISTS cuotas_extraordinaria_unica');
        DB::statement('DROP INDEX IF EXISTS cuotas_ordinaria_unica');
        Schema::table('cuotas', function (Blueprint $table) {
            $table->dropColumn('detalle');
            $table->unique(['condominio_id', 'unidad_id', 'periodo', 'concepto']);
        });
    }
};
