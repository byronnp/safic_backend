<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paquetería: el guardia recibe un paquete con su foto, avisa a la unidad y lo entrega a un ocupante vigente (o a una persona
 * autorizada por el residente) con su firma. Los datos de quien retira se anonimizan a los 12 meses (LOPDP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paquetes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->restrictOnDelete();
            $table->string('empresa', 80);
            $table->string('descripcion', 60)->nullable();                 // «Caja mediana», «Sobre»…
            $table->string('foto_ruta', 255);
            $table->string('estado', 10)->default('en_garita');            // en_garita, entregado
            $table->timestampTz('recibido_en');
            $table->foreignId('recibido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('recordatorio_en')->nullable();            // último aviso de «lleva más de 7 días»
            $table->timestampTz('entregado_en')->nullable();
            $table->foreignId('entregado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('retira_tipo', 10)->nullable();                 // ocupante, autorizado
            $table->string('retira_nombre', 120)->nullable();
            $table->string('firma_ruta', 255)->nullable();
            $table->timestampTz('anonimizado_en')->nullable();
            $table->timestamps();

            $table->index(['condominio_id', 'estado']);
            $table->index(['unidad_id', 'estado']);
        });

        RowLevelSecurity::enable('paquetes');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('paquetes');
        Schema::dropIfExists('paquetes');
    }
};
