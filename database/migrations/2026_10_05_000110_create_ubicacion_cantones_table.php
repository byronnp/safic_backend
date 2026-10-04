<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de plataforma: cantones del Ecuador (código INEC de 4 dígitos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ubicacion_cantones', function (Blueprint $table) {
            $table->char('codigo', 4)->primary();
            $table->char('provincia_codigo', 2);
            $table->string('nombre', 80);
            $table->decimal('latitud', 9, 6)->nullable();
            $table->decimal('longitud', 9, 6)->nullable();
            $table->foreign('provincia_codigo')->references('codigo')->on('ubicacion_provincias')->cascadeOnUpdate();
            $table->index('provincia_codigo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicacion_cantones');
    }
};
