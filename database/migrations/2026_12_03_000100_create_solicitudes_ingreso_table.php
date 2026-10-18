<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de ingreso: cuando llega alguien que nadie anunció, el guardia le pregunta al residente desde la app. El residente
 * permite o rechaza en pocos minutos; si no responde, la solicitud vence y el guardia vuelve a la llamada telefónica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_ingreso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->string('visitante_nombre', 120);
            $table->string('tipo', 12);                                    // visita, delivery, proveedor
            $table->string('placa', 12)->nullable();
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado', 10)->default('pendiente');            // pendiente, permitida, rechazada, cancelada, usada
            $table->timestampTz('expira_en');
            $table->foreignId('respondida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('respondida_en')->nullable();
            $table->foreignId('acceso_id')->nullable()->constrained('accesos_garita')->nullOnDelete();
            $table->timestamps();

            $table->index(['condominio_id', 'estado', 'created_at']);
            $table->index(['unidad_id', 'estado']);
        });

        RowLevelSecurity::enable('solicitudes_ingreso');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('solicitudes_ingreso');
        Schema::dropIfExists('solicitudes_ingreso');
    }
};
