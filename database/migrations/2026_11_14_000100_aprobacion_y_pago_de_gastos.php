<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobación en dos niveles y pago de las facturas de proveedores. `gasto_aprobaciones` guarda cada
 * decisión (quién, nivel, comentario, si fue en subrogación); `pagos_proveedor` y su aplicación
 * a facturas permiten abonos; `configuraciones_finanzas` guarda el umbral de la 2.ª aprobación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones_finanzas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->unique()->constrained('condominios')->cascadeOnDelete();
            $table->decimal('umbral_segunda_aprobacion', 12, 2)->default(500);
            $table->boolean('aprobador_puede_pagar')->default(false);
            $table->timestamps();
        });

        Schema::table('gastos', function (Blueprint $table) {
            $table->unsignedSmallInteger('nivel_aprobado')->default(0);
            $table->string('motivo_rechazo', 300)->nullable();
        });

        Schema::create('gasto_aprobaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('gasto_id')->constrained('gastos')->cascadeOnDelete();
            $table->unsignedSmallInteger('nivel');                       // 1, 2
            $table->string('decision', 10);                             // aprobada, rechazada
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comentario', 300)->nullable();
            $table->boolean('en_subrogacion')->default(false);
            $table->timestamps();

            $table->index('gasto_id');
        });

        Schema::create('pagos_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->unsignedBigInteger('numero');                       // PP-0087, consecutivo por condominio
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->foreignId('proveedor_cuenta_id')->nullable()->constrained('proveedor_cuentas')->nullOnDelete();
            $table->foreignId('cuenta_bancaria_id')->nullable()->constrained('cuentas_bancarias')->nullOnDelete();
            $table->decimal('monto', 12, 2);
            $table->date('fecha_pago');
            $table->string('referencia', 40);
            $table->string('comprobante_ruta', 255)->nullable();
            $table->string('estado', 10)->default('registrado');         // registrado, anulado
            $table->string('motivo_anulacion', 300)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_en')->nullable();
            $table->timestamps();

            $table->unique(['condominio_id', 'numero']);
            $table->index(['condominio_id', 'proveedor_id']);
        });
        DB::statement("CREATE UNIQUE INDEX pagos_proveedor_referencia_unica ON pagos_proveedor (condominio_id, proveedor_id, lower(referencia)) WHERE estado = 'registrado'");
        DB::statement('ALTER TABLE pagos_proveedor ADD CONSTRAINT pagos_proveedor_monto_positivo CHECK (monto > 0)');

        Schema::create('pago_proveedor_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('pago_proveedor_id')->constrained('pagos_proveedor')->cascadeOnDelete();
            $table->foreignId('gasto_id')->constrained('gastos')->restrictOnDelete();
            $table->decimal('monto', 12, 2);
            $table->timestamps();

            $table->unique(['pago_proveedor_id', 'gasto_id']);
        });

        RowLevelSecurity::enable('configuraciones_finanzas');
        RowLevelSecurity::enable('gasto_aprobaciones');
        RowLevelSecurity::enable('pagos_proveedor');
        RowLevelSecurity::enable('pago_proveedor_aplicaciones');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('pago_proveedor_aplicaciones');
        RowLevelSecurity::disable('pagos_proveedor');
        RowLevelSecurity::disable('gasto_aprobaciones');
        RowLevelSecurity::disable('configuraciones_finanzas');
        Schema::dropIfExists('pago_proveedor_aplicaciones');
        Schema::dropIfExists('pagos_proveedor');
        Schema::dropIfExists('gasto_aprobaciones');
        Schema::table('gastos', function (Blueprint $table) {
            $table->dropColumn(['nivel_aprobado', 'motivo_rechazo']);
        });
        Schema::dropIfExists('configuraciones_finanzas');
    }
};
