<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidencias: un residente (o el guardia) reporta un problema con fotos; la administración lo gestiona por estados
 * (abierto, en proceso, resuelto, cerrado), comenta al residente y deja notas internas que él no ve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->unsignedInteger('numero');                              // consecutivo por condominio: #214
            $table->foreignId('unidad_id')->nullable()->constrained('unidades')->nullOnDelete();   // null = áreas comunes
            $table->foreignId('reportado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('titulo', 120);
            $table->text('descripcion');
            $table->string('categoria', 14);                                // mantenimiento, convivencia, seguridad, areas_verdes, otro
            $table->string('prioridad', 6)->default('media');               // alta, media, baja
            $table->string('estado', 10)->default('abierto');               // abierto, en_proceso, resuelto, cerrado
            $table->timestampTz('primera_respuesta_en')->nullable();
            $table->timestampTz('resuelta_en')->nullable();
            $table->timestampTz('cerrada_en')->nullable();
            $table->string('cierre', 10)->nullable();                       // residente, automatico, administracion
            $table->timestamps();

            $table->unique(['condominio_id', 'numero']);
            $table->index(['condominio_id', 'estado']);
        });

        Schema::create('incidencia_fotos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('incidencia_id')->constrained('incidencias')->cascadeOnDelete();
            $table->string('ruta', 255);
            $table->unsignedSmallInteger('orden')->default(1);
            $table->timestamps();
        });

        Schema::create('incidencia_comentarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('incidencia_id')->constrained('incidencias')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('de_administracion')->default(false);
            $table->boolean('interna')->default(false);                      // nota interna: el residente no la ve
            $table->text('texto');
            $table->timestamps();

            $table->index(['incidencia_id', 'created_at']);
        });

        RowLevelSecurity::enable('incidencias');
        RowLevelSecurity::enable('incidencia_fotos');
        RowLevelSecurity::enable('incidencia_comentarios');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('incidencia_comentarios');
        RowLevelSecurity::disable('incidencia_fotos');
        RowLevelSecurity::disable('incidencias');
        Schema::dropIfExists('incidencia_comentarios');
        Schema::dropIfExists('incidencia_fotos');
        Schema::dropIfExists('incidencias');
    }
};
