<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobación de pagos: la tolerancia bancaria del condominio, el número del recibo de cada pago
 * aprobado y la tabla de secuencias (números consecutivos por condominio, sin repetir ni saltar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_cobro', function (Blueprint $table) {
            // Diferencia de centavos con el banco que no impide dar la cuota por pagada
            $table->decimal('tolerancia_bancaria', 5, 2)->default('0.50');
        });

        Schema::table('pagos', function (Blueprint $table) {
            $table->unsignedInteger('recibo_numero')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX pagos_recibo_unico ON pagos (condominio_id, recibo_numero) WHERE recibo_numero IS NOT NULL');

        Schema::create('secuencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('nombre', 30);
            $table->unsignedBigInteger('ultimo')->default(0);
            $table->timestamps();
            $table->unique(['condominio_id', 'nombre']);
        });
        RowLevelSecurity::enable('secuencias');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('secuencias');
        Schema::dropIfExists('secuencias');
        DB::statement('DROP INDEX IF EXISTS pagos_recibo_unico');
        Schema::table('pagos', fn (Blueprint $t) => $t->dropColumn('recibo_numero'));
        Schema::table('configuraciones_cobro', fn (Blueprint $t) => $t->dropColumn('tolerancia_bancaria'));
    }
};
