<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos de los daños que el guardia reporta al recibir un área común (hasta 5 por reserva). Respaldan la decisión de la
 * administración sobre la garantía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva_danos_fotos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('reserva_id')->constrained('reservas')->cascadeOnDelete();
            $table->string('ruta', 255);
            $table->unsignedSmallInteger('orden')->default(1);
            $table->timestamps();

            $table->index(['reserva_id', 'orden']);
        });

        RowLevelSecurity::enable('reserva_danos_fotos');
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_danos_fotos');
    }
};
