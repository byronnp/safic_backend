<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las reglas de dinero del condominio (umbral de segunda aprobación, quién puede pagar lo que
 * aprobó y la tolerancia bancaria) viven juntas en `configuraciones_finanzas` y se editan desde
 * una sola pantalla. La tolerancia se mueve desde `configuraciones_cobro` conservando su valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_finanzas', function (Blueprint $table) {
            $table->decimal('tolerancia_bancaria', 5, 2)->default('0.50');
        });

        // Se conserva lo que ya hubiera configurado cada condominio (esta migración corre con el usuario dueño)
        DB::statement('
            INSERT INTO configuraciones_finanzas (condominio_id, tolerancia_bancaria, created_at, updated_at)
            SELECT condominio_id, tolerancia_bancaria, now(), now() FROM configuraciones_cobro WHERE tolerancia_bancaria <> 0.50
            ON CONFLICT (condominio_id) DO UPDATE SET tolerancia_bancaria = EXCLUDED.tolerancia_bancaria
        ');

        Schema::table('configuraciones_cobro', fn (Blueprint $t) => $t->dropColumn('tolerancia_bancaria'));
    }

    public function down(): void
    {
        Schema::table('configuraciones_cobro', fn (Blueprint $t) => $t->decimal('tolerancia_bancaria', 5, 2)->default('0.50'));

        DB::statement('
            UPDATE configuraciones_cobro c SET tolerancia_bancaria = f.tolerancia_bancaria
            FROM configuraciones_finanzas f WHERE f.condominio_id = c.condominio_id
        ');

        Schema::table('configuraciones_finanzas', fn (Blueprint $t) => $t->dropColumn('tolerancia_bancaria'));
    }
};
