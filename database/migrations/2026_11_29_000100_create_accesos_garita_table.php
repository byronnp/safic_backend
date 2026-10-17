<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de garita: cada ingreso y salida de visitas, proveedores y delivery. Un acceso con QR enlaza a su autorización;
 * uno sin QR lo registra el guardia tras confirmar por llamada. Los datos del visitante se anonimizan a los 12 meses (LOPDP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accesos_garita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('autorizacion_id')->nullable()->constrained('autorizaciones_visita')->nullOnDelete();
            $table->foreignId('unidad_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->string('tipo', 12);                                    // visita, proveedor, delivery
            $table->string('nombre', 120);
            $table->text('documento')->nullable();                         // cifrado
            $table->string('placa', 12)->nullable();
            $table->string('destino', 80)->nullable();                     // proveedores: «Torre B», «Ascensores»…
            $table->string('autorizado_por', 16);                          // qr, llamada, app_residente, administracion
            $table->string('estado', 8)->default('dentro');                // dentro, salio
            $table->timestampTz('entrada_en');
            $table->timestampTz('salida_en')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('salida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('anonimizado_en')->nullable();
            $table->timestamps();

            $table->index(['condominio_id', 'entrada_en']);
            $table->index(['condominio_id', 'estado']);
            $table->index(['unidad_id', 'entrada_en']);
        });
        DB::statement('ALTER TABLE accesos_garita ADD CONSTRAINT accesos_garita_salida CHECK (salida_en IS NULL OR salida_en >= entrada_en)');

        RowLevelSecurity::enable('accesos_garita');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('accesos_garita');
        Schema::dropIfExists('accesos_garita');
    }
};
