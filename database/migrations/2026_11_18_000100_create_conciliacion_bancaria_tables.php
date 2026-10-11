<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conciliación bancaria. `estados_cuenta_bancarios` es un estado de cuenta importado (uno por cuenta
 * y mes; volver a importar suma solo lo nuevo). `movimientos_bancarios` son sus líneas, con su
 * clasificación y, si se cruzaron, el pago de residente o a proveedor al que corresponden.
 * `reglas_conciliacion` guarda «descripción que contiene X → tipo» y `ajustes_conciliacion` las
 * diferencias que alguien justificó con un motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estados_cuenta_bancarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('cuenta_bancaria_id')->constrained('cuentas_bancarias')->cascadeOnDelete();
            $table->date('periodo');                                      // siempre el día 1
            $table->string('archivo_nombre', 160);
            $table->decimal('saldo_inicial', 14, 2)->nullable();
            $table->decimal('saldo_final', 14, 2)->nullable();
            $table->unsignedInteger('duplicados')->default(0);            // líneas descartadas en la última importación
            $table->foreignId('importado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cuenta_bancaria_id', 'periodo']);
        });

        Schema::create('movimientos_bancarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('estado_cuenta_id')->constrained('estados_cuenta_bancarios')->cascadeOnDelete();
            $table->foreignId('cuenta_bancaria_id')->constrained('cuentas_bancarias')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('descripcion', 200);
            $table->string('referencia', 40)->nullable();
            $table->decimal('monto', 14, 2);                              // crédito (+) o débito (−)
            $table->decimal('saldo', 14, 2)->nullable();
            $table->char('huella', 64);                                   // para no importar dos veces la misma línea
            $table->string('estado', 16);                                 // automatico, sugerido, sin_identificar, conciliado
            $table->string('tipo', 16)->nullable();                       // pago_residente, pago_proveedor, interes, comision, otro
            $table->foreignId('pago_id')->nullable()->constrained('pagos')->nullOnDelete();
            $table->foreignId('pago_proveedor_id')->nullable()->constrained('pagos_proveedor')->nullOnDelete();
            $table->string('propuesta_tipo', 16)->nullable();             // lo que se sugiere cuando estado = sugerido
            $table->unsignedBigInteger('propuesta_id')->nullable();       // pago o pago a proveedor sugerido
            $table->string('propuesta_texto', 200)->nullable();
            $table->foreignId('conciliado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('conciliado_en')->nullable();
            $table->timestamps();

            $table->unique(['cuenta_bancaria_id', 'huella']);
            $table->index(['estado_cuenta_id', 'estado']);
        });
        // Un pago se concilia con una sola línea del banco
        DB::statement('CREATE UNIQUE INDEX movimientos_pago_unico ON movimientos_bancarios (pago_id) WHERE pago_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX movimientos_pago_proveedor_unico ON movimientos_bancarios (pago_proveedor_id) WHERE pago_proveedor_id IS NOT NULL');

        Schema::create('reglas_conciliacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('patron', 80);                                 // la descripción «contiene» esto
            $table->string('tipo', 16);                                   // interes, comision, otro
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX reglas_conciliacion_patron_unico ON reglas_conciliacion (condominio_id, lower(patron))');

        Schema::create('ajustes_conciliacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('estado_cuenta_id')->constrained('estados_cuenta_bancarios')->cascadeOnDelete();
            $table->decimal('monto', 14, 2);                              // positivo suma al saldo de la app, negativo resta
            $table->string('motivo', 300);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        foreach (['estados_cuenta_bancarios', 'movimientos_bancarios', 'reglas_conciliacion', 'ajustes_conciliacion'] as $tabla) {
            RowLevelSecurity::enable($tabla);
        }
    }

    public function down(): void
    {
        foreach (['ajustes_conciliacion', 'reglas_conciliacion', 'movimientos_bancarios', 'estados_cuenta_bancarios'] as $tabla) {
            RowLevelSecurity::disable($tabla);
            Schema::dropIfExists($tabla);
        }
    }
};
