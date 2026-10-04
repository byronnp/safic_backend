<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Constancia de aceptación del aviso de privacidad (LOPDP): quién, qué versión,
 * cuándo y desde dónde. Tabla de plataforma (sin RLS): la aceptación es de la
 * persona, no de un condominio. Solo se insertan filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aceptaciones_privacidad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('version', 40);
            $table->string('origen', 20);                  // invitacion (luego: actualizacion del aviso)
            $table->timestampTz('aceptado_en');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aceptaciones_privacidad');
    }
};
