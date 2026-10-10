<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proveedores del condominio y sus facturas (gastos). La cuenta bancaria de un proveedor tiene
 * historial: un cambio queda `pendiente` con `activa_en` (24 h) y mientras tanto se paga a la
 * cuenta vigente. Las facturas nacen `por_aprobar`; la aprobación y el pago llegan después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('ruc', 13);
            $table->string('razon_social', 160);
            $table->string('categoria', 60)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['condominio_id', 'ruc']);
        });

        Schema::create('proveedor_cuentas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $table->string('banco', 60);
            $table->string('tipo', 10);                       // corriente, ahorros
            $table->text('numero');                           // cifrado
            $table->string('titular', 120);
            $table->string('estado', 12);                     // pendiente, activa, reemplazada, cancelada
            $table->timestamp('activa_en')->nullable();       // cuándo pasa a ser la vigente
            $table->foreignId('solicitada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['proveedor_id', 'estado']);
        });
        // Una sola cuenta vigente y un solo cambio en espera por proveedor
        DB::statement("CREATE UNIQUE INDEX proveedor_cuenta_activa_unica ON proveedor_cuentas (proveedor_id) WHERE estado = 'activa'");
        DB::statement("CREATE UNIQUE INDEX proveedor_cuenta_pendiente_unica ON proveedor_cuentas (proveedor_id) WHERE estado = 'pendiente'");

        Schema::create('gastos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->string('numero', 17);                     // 001-002-000004512
            $table->string('clave_acceso', 49)->nullable();   // del XML del SRI
            $table->string('origen', 10);                     // xml, manual
            $table->string('categoria', 60)->nullable();
            $table->string('descripcion', 200)->nullable();
            $table->date('fecha_emision');
            $table->date('vence_el');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('iva', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('pagado', 12, 2)->default(0);
            $table->string('estado', 12)->default('por_aprobar'); // por_aprobar, aprobada, rechazada, pagada
            $table->string('xml_ruta', 255)->nullable();
            $table->string('pdf_ruta', 255)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['condominio_id', 'estado']);
            $table->index(['proveedor_id']);
        });
        // La misma factura no se registra dos veces: por clave de acceso, o por proveedor + número
        DB::statement('CREATE UNIQUE INDEX gastos_clave_unica ON gastos (condominio_id, clave_acceso) WHERE clave_acceso IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX gastos_numero_unico ON gastos (condominio_id, proveedor_id, numero)');
        DB::statement('ALTER TABLE gastos ADD CONSTRAINT gastos_total_positivo CHECK (total > 0 AND pagado >= 0 AND pagado <= total)');

        RowLevelSecurity::enable('proveedores');
        RowLevelSecurity::enable('proveedor_cuentas');
        RowLevelSecurity::enable('gastos');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('gastos');
        RowLevelSecurity::disable('proveedor_cuentas');
        RowLevelSecurity::disable('proveedores');
        Schema::dropIfExists('gastos');
        Schema::dropIfExists('proveedor_cuentas');
        Schema::dropIfExists('proveedores');
    }
};
