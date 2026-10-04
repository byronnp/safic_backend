<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provincias del Ecuador con el código de la división político-administrativa
 * del INEC (dos dígitos). Catálogo de plataforma: sin RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provincias', function (Blueprint $table) {
            $table->id();
            $table->char('codigo', 2)->unique();
            $table->string('nombre', 60);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provincias');
    }
};
