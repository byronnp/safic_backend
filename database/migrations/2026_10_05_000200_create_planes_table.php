<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes de la plataforma (Básico, Profesional, Completo). Tabla de plataforma, sin RLS.
 * limite_administrativos: cuántos usuarios administrativos (administrador, tesorero,
 * contador) puede tener un condominio con este plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 60);
            $table->unsignedSmallInteger('limite_administrativos');
            $table->decimal('valor_unidad_sugerido', 12, 2);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planes');
    }
};
