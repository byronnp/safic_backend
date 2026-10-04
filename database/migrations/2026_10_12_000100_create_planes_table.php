<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes de la plataforma (Básico, Profesional, Completo). Tabla de plataforma:
 * sin condominio_id ni RLS. Los módulos por plan y el cobro llegan en la fase 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 30)->unique();
            $table->string('nombre', 60);
            // Administrador, tesorero y contador cuentan para este límite
            $table->unsignedSmallInteger('max_administrativos');
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
