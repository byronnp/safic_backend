<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 · Votación. Una votación por punto del orden del día y un voto por unidad (índice único). El voto no se edita: queda con su
 * alícuota de la foto congelada, fecha, canal (app o mesa) y un hash encadenado (sha256 del voto anterior + los datos del voto) que
 * permite comprobar después que nadie alteró los resultados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('asamblea_id')->constrained('asambleas')->cascadeOnDelete();
            $table->foreignId('punto_id')->constrained('asamblea_puntos')->cascadeOnDelete();
            $table->string('estado', 8)->default('abierta');                // abierta, cerrada
            $table->boolean('secreta')->default(false);                     // regla de voto secreto vigente al abrir
            $table->string('mayoria', 10);                                  // copia del punto al abrir: simple o calificada
            $table->decimal('umbral_pct', 5, 2)->nullable();
            $table->string('base', 10);                                     // presentes o total
            $table->foreignId('abierta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('abierta_en');
            $table->timestampTz('cerrada_en')->nullable();
            // Resultado calculado al cerrar
            $table->decimal('base_alicuota', 8, 4)->nullable();
            $table->decimal('alicuota_si', 8, 4)->nullable();
            $table->decimal('alicuota_no', 8, 4)->nullable();
            $table->decimal('alicuota_abstencion', 8, 4)->nullable();
            $table->unsignedInteger('votos_si')->nullable();
            $table->unsignedInteger('votos_no')->nullable();
            $table->unsignedInteger('votos_abstencion')->nullable();
            $table->string('resultado', 10)->nullable();                    // aprobado o rechazado
            $table->timestamps();

            $table->unique('punto_id');                                     // una votación por punto
        });

        Schema::create('votos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('votacion_id')->constrained('votaciones')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->string('opcion', 10);                                   // si, no, abstencion
            $table->decimal('alicuota', 7, 4);                              // la de la foto congelada al instalar
            $table->foreignId('emitido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('canal', 5);                                     // app o mesa
            $table->timestampTz('emitido_en');
            $table->string('hash', 64);
            $table->timestamps();

            $table->unique(['votacion_id', 'unidad_id']);                   // un voto por unidad y votación
        });

        RowLevelSecurity::enable('votaciones');
        RowLevelSecurity::enable('votos');
    }

    public function down(): void
    {
        Schema::dropIfExists('votos');
        Schema::dropIfExists('votaciones');
    }
};
