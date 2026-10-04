<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo global de amenidades que administra el super admin. Cada condominio
 * elige las suyas en el alta (y en S3 puede agregar propias). Sin RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenidades_catalogo', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 40)->unique();
            $table->string('nombre', 80);
            $table->string('icono', 60);                    // Material Symbols Rounded (sym_r_*)
            $table->boolean('reservable')->default(false);   // pasa a la agenda de áreas comunes
            $table->boolean('esencial')->default(false);     // nunca se restringe por mora
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amenidades_catalogo');
    }
};
