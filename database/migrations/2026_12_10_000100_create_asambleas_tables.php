<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 · Asambleas y votaciones. Aquí la base: la asamblea con su orden del día y las reglas que cada condominio ajusta. Las
 * tablas de asistencia, votos, actas y resoluciones llegan con sus entregas.
 *
 * Estados: borrador → convocada → en_registro → instalada → en_votacion → cerrada → acta_firmada (o cancelada con motivo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asambleas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('titulo', 120);
            $table->string('tipo', 14);                                     // ordinaria, extraordinaria
            $table->string('modalidad', 12)->default('presencial');         // presencial (con voto en la app), mixta, virtual
            $table->timestampTz('fecha_hora');                              // inicio de la primera convocatoria
            $table->string('lugar', 160)->nullable();
            $table->string('enlace_video', 300)->nullable();                // Zoom, Meet o Teams (no hay videoconferencia propia)
            $table->string('estado', 14)->default('borrador');
            $table->unsignedSmallInteger('convocatoria')->default(1);       // 1.ª o 2.ª convocatoria con la que se instaló
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('convocada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('convocada_en')->nullable();
            $table->timestampTz('instalada_en')->nullable();
            $table->timestampTz('cerrada_en')->nullable();
            $table->timestampTz('cancelada_en')->nullable();
            $table->string('motivo_cancelacion', 300)->nullable();
            $table->timestamps();

            $table->index(['condominio_id', 'estado', 'fecha_hora']);
        });

        Schema::create('asamblea_puntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('asamblea_id')->constrained('asambleas')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden');
            $table->string('titulo', 160);
            $table->text('descripcion')->nullable();
            $table->string('tipo', 20);                                     // informativo, votacion, eleccion, reforma_reglamento
            $table->string('mayoria', 10)->default('ninguna');              // ninguna, simple, calificada
            $table->decimal('umbral_pct', 5, 2)->nullable();                // solo calificada: % que debe alcanzar el «sí»
            $table->string('base', 10)->nullable();                         // presentes o total (sobre qué alícuotas se calcula)
            $table->string('adjunto_ruta', 255)->nullable();
            $table->string('adjunto_nombre', 120)->nullable();
            $table->timestamps();

            $table->unique(['asamblea_id', 'orden']);
        });

        Schema::create('configuracion_asambleas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->unique()->constrained('condominios')->cascadeOnDelete();
            // Valores por defecto de la Ley de Propiedad Horizontal; cada condominio los ajusta si su reglamento interno lo indica
            $table->boolean('permite_virtual')->default(false);             // asambleas virtuales o mixtas solo si el reglamento lo permite
            $table->boolean('mora_cuenta_para_quorum')->default(true);      // la unidad morosa suma al quórum pero no vota
            $table->boolean('voto_secreto')->default(false);
            $table->decimal('porcentaje_reforma', 5, 2)->nullable();        // % para reformar el reglamento: sin valor hasta confirmarlo con asesoría legal
            $table->timestamps();
        });

        RowLevelSecurity::enable('asambleas');
        RowLevelSecurity::enable('asamblea_puntos');
        RowLevelSecurity::enable('configuracion_asambleas');
    }

    public function down(): void
    {
        Schema::dropIfExists('asamblea_puntos');
        Schema::dropIfExists('asambleas');
        Schema::dropIfExists('configuracion_asambleas');
    }
};
