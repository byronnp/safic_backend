<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos de los residentes. `cuentas_bancarias` es adonde transfieren (el número va cifrado);
 * `pagos` guarda cada transferencia con su comprobante y espera la revisión de la administración;
 * `pago_aplicaciones` dice a qué cuotas se aplicó un pago aprobado. El saldo a favor de una
 * unidad no se guarda: es lo aprobado menos lo aplicado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_bancarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('banco', 60);
            $table->string('tipo', 10);                      // corriente, ahorros
            $table->text('numero');                          // cifrado
            $table->string('titular', 120);
            $table->boolean('es_principal')->default(false);
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX cuentas_bancarias_principal_unica ON cuentas_bancarias (condominio_id) WHERE es_principal AND activa');

        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();      // quién lo envió o registró
            $table->string('metodo', 15)->default('transferencia');                              // transferencia, efectivo
            $table->decimal('monto', 12, 2);
            $table->foreignId('cuenta_bancaria_id')->nullable()->constrained('cuentas_bancarias')->nullOnDelete();
            $table->string('numero_comprobante', 40)->nullable();
            $table->string('banco_origen', 60)->nullable();
            $table->date('fecha_pago');
            $table->string('comprobante_ruta', 255)->nullable();
            $table->string('estado', 12)->default('pendiente');                                  // pendiente, aprobado, rechazado, anulado
            $table->string('motivo_rechazo', 300)->nullable();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable();
            $table->decimal('diferencia', 12, 2)->default(0);                                    // recibido − esperado, dentro de la tolerancia
            $table->jsonb('cuotas_declaradas')->nullable();                                      // lo que el residente dijo pagar
            $table->timestamps();

            $table->index(['condominio_id', 'estado']);
            $table->index(['condominio_id', 'unidad_id']);
        });
        DB::statement("CREATE UNIQUE INDEX pagos_comprobante_unico ON pagos (condominio_id, lower(numero_comprobante)) WHERE numero_comprobante IS NOT NULL AND estado NOT IN ('rechazado', 'anulado')");
        DB::statement('ALTER TABLE pagos ADD CONSTRAINT pagos_monto_positivo CHECK (monto > 0)');

        Schema::create('pago_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('pago_id')->constrained('pagos')->cascadeOnDelete();
            $table->foreignId('cuota_id')->constrained('cuotas')->cascadeOnDelete();
            $table->decimal('monto', 12, 2);             // dinero aplicado
            $table->decimal('ajuste', 12, 2)->default(0); // centavos absorbidos por la tolerancia
            $table->timestamps();
            $table->unique(['pago_id', 'cuota_id']);
        });

        RowLevelSecurity::enable('cuentas_bancarias');
        RowLevelSecurity::enable('pagos');
        RowLevelSecurity::enable('pago_aplicaciones');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('pago_aplicaciones');
        RowLevelSecurity::disable('pagos');
        RowLevelSecurity::disable('cuentas_bancarias');
        Schema::dropIfExists('pago_aplicaciones');
        Schema::dropIfExists('pagos');
        Schema::dropIfExists('cuentas_bancarias');
    }
};
