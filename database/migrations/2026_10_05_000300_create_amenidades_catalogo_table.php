<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo global de amenidades que administra el super admin. Tabla de plataforma,
 * sin RLS. Cada condominio elige las suyas en condominio_amenidades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenidades_catalogo', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80)->unique();
            $table->string('categoria', 12);                 // recreacion, deporte, social, servicios, seguridad
            $table->string('descripcion', 200)->nullable();
            $table->boolean('reservable')->default(false);
            $table->boolean('esencial')->default(false);     // nunca se restringe por mora (Decreto 462)
            $table->boolean('requiere_aprobacion')->default(false);
            $table->unsignedSmallInteger('capacidad')->nullable();
            $table->unsignedSmallInteger('duracion_maxima_min')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amenidades_catalogo');
    }
};
