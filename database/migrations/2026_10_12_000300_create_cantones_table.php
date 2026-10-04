<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cantones (código INEC de cuatro dígitos: provincia + cantón). Sin RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cantones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provincia_id')->constrained('provincias')->cascadeOnDelete();
            $table->char('codigo', 4)->unique();
            $table->string('nombre', 80);
            $table->timestamps();
            $table->index(['provincia_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cantones');
    }
};
