<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de nivel plataforma: condominios y membresías de usuarios.
 * No llevan RLS porque se consultan antes de conocer el condominio (login, selector).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condominios', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 12)->unique();          // SF-0001: referencia en transferencias
            $table->string('nombre', 120);
            $table->string('tipo', 20)->default('conjunto');  // conjunto, edificio, urbanizacion, mixto
            $table->string('ruc', 13)->nullable();
            $table->string('razon_social', 160)->nullable();
            $table->unsignedInteger('total_unidades');         // límite estricto de registro
            $table->char('moneda', 3)->default('USD');
            $table->char('pais', 2)->default('EC');
            $table->string('zona_horaria', 40)->default('America/Guayaquil');
            $table->string('estado', 20)->default('prueba');   // prueba, activo, solo_lectura, suspendido
            $table->jsonb('marca')->nullable();                // logo y colores (Apariencia)
            $table->timestamps();
        });

        Schema::create('condominio_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->boolean('es_principal')->default(false);
            $table->boolean('activo')->default(true);
            $table->date('acceso_hasta')->nullable();          // vigencia (obligatoria para contador)
            $table->timestamps();
            $table->unique(['user_id', 'condominio_id']);
        });

        // Un solo condominio principal por usuario
        DB::statement('CREATE UNIQUE INDEX condominio_user_un_principal ON condominio_user (user_id) WHERE es_principal');
    }

    public function down(): void
    {
        Schema::dropIfExists('condominio_user');
        Schema::dropIfExists('condominios');
    }
};
