<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de plataforma: parroquias urbanas y rurales (código INEC de 6 dígitos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ubicacion_parroquias', function (Blueprint $table) {
            $table->char('codigo', 6)->primary();
            $table->char('canton_codigo', 4);
            $table->string('nombre', 100);
            $table->foreign('canton_codigo')->references('codigo')->on('ubicacion_cantones')->cascadeOnUpdate();
            $table->index('canton_codigo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicacion_parroquias');
    }
};
