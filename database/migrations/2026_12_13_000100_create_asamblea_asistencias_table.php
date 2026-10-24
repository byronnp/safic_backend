<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 · Asistencia. Una fila por unidad que está (o estuvo) en la sala: quién asistió, de qué forma (presencial, virtual o por
 * poder) y cuándo entró y salió. Las salidas bajan el quórum. Una unidad solo puede estar presente una vez a la vez (índice parcial);
 * si sale y vuelve, hay otra fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asamblea_asistencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('asamblea_id')->constrained('asambleas')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->foreignId('asistente_persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->string('asistente_nombre', 120);
            $table->string('forma', 10);                                    // presencial, virtual, poder
            $table->foreignId('poder_id')->nullable()->constrained('asamblea_poderes')->nullOnDelete();
            $table->string('canal', 5);                                     // mesa (lo marcó el secretario) o app
            $table->foreignId('registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('ingreso_en');
            $table->timestampTz('salida_en')->nullable();
            $table->timestampTz('ultima_actividad_en');                     // un asistente virtual sin actividad por 10 minutos deja de contar
            $table->timestamps();

            $table->index(['asamblea_id', 'salida_en']);
        });

        DB::statement('CREATE UNIQUE INDEX asamblea_asistencias_presente_unico ON asamblea_asistencias (asamblea_id, unidad_id) WHERE salida_en IS NULL');

        RowLevelSecurity::enable('asamblea_asistencias');
    }

    public function down(): void
    {
        Schema::dropIfExists('asamblea_asistencias');
    }
};
