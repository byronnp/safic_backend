<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitaciones para crear la contraseña y entrar por primera vez. Tabla de plataforma
 * (sin RLS): se consulta sin sesión ni condominio, solo con el token del correo.
 * Del token se guarda solo el hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expira_en');
            $table->timestampTz('aceptada_en')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'condominio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitaciones');
    }
};
