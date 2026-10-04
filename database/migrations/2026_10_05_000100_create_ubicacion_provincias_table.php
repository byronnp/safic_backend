<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de plataforma: provincias del Ecuador con su código INEC.
 * Sin RLS: es el mismo para todos los condominios. Se llena con CatalogosSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ubicacion_provincias', function (Blueprint $table) {
            $table->char('codigo', 2)->primary();
            $table->string('nombre', 80);
            $table->decimal('latitud', 9, 6)->nullable();
            $table->decimal('longitud', 9, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicacion_provincias');
    }
};
