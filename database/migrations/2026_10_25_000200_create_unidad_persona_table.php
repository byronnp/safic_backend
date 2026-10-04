<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ocupantes: relación de una persona con una unidad (propietario, inquilino,
 * residente o contacto de emergencia) con fechas de vigencia. Terminar una
 * ocupación no borra: se llena fecha_fin y queda el historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidad_persona', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->string('relacion', 20);                // propietario, inquilino, residente, contacto_emergencia
            $table->boolean('es_principal')->default(false);
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->timestamps();
            $table->index(['condominio_id', 'unidad_id']);
            $table->index(['condominio_id', 'persona_id']);
        });

        // Respaldo en la base: un solo ocupante principal sin fecha de fin por unidad.
        // La regla completa (fechas que se cruzan) la valida AsignarOcupanteAction.
        DB::statement('CREATE UNIQUE INDEX unidad_persona_principal_unico ON unidad_persona (unidad_id) WHERE es_principal AND fecha_fin IS NULL');

        RowLevelSecurity::enable('unidad_persona');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('unidad_persona');
        Schema::dropIfExists('unidad_persona');
    }
};
