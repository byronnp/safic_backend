<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 · Cierre y acta. Al cerrar la asamblea se congela el acta (`contenido`: asistencia, quórum, poderes, resultados y
 * resoluciones tal como quedaron) y se numera por condominio. La firman el presidente (o su subrogante) y el secretario; con ambas
 * firmas el acta queda firmada y se publica a los propietarios junto con las resoluciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asambleas', function (Blueprint $table) {
            $table->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('actas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('asamblea_id')->unique()->constrained('asambleas')->cascadeOnDelete();
            $table->unsignedInteger('numero');                              // numerada por condominio
            $table->jsonb('contenido');                                     // foto del acta al cerrar: no cambia aunque cambien los datos
            $table->string('hash', 64);                                     // sha256 del contenido
            $table->string('estado', 10)->default('pendiente');             // pendiente de firmas, firmada
            $table->foreignId('presidente_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('presidente_nombre', 120)->nullable();
            $table->boolean('presidente_en_subrogacion')->default(false);   // firmó el vicepresidente por ausencia del presidente
            $table->timestampTz('firmada_presidente_en')->nullable();
            $table->foreignId('secretario_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('secretario_nombre', 120)->nullable();
            $table->timestampTz('firmada_secretario_en')->nullable();
            $table->timestampTz('publicada_en')->nullable();
            $table->timestamps();

            $table->unique(['condominio_id', 'numero']);
        });

        Schema::create('resoluciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('asamblea_id')->constrained('asambleas')->cascadeOnDelete();
            $table->foreignId('acta_id')->constrained('actas')->cascadeOnDelete();
            $table->foreignId('punto_id')->nullable()->constrained('asamblea_puntos')->nullOnDelete();
            $table->text('texto');
            $table->date('vigente_desde');                                  // el día de la asamblea
            $table->timestamps();

            $table->index(['condominio_id', 'vigente_desde']);
        });

        RowLevelSecurity::enable('actas');
        RowLevelSecurity::enable('resoluciones');
    }

    public function down(): void
    {
        Schema::dropIfExists('resoluciones');
        Schema::dropIfExists('actas');
        Schema::table('asambleas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cerrada_por');
        });
    }
};
