<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cargos de la directiva (presidente, vicepresidente, secretario y tesorero): quién los
 * ocupa, desde cuándo, hasta cuándo y con qué acta. Cerrar un periodo no borra: se llena
 * `cerrado_en` y queda el historial. Un cargo lo ocupa una sola persona y una persona
 * ocupa un solo cargo (los índices lo respaldan; la regla completa la valida AsignarCargoAction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargos_directiva', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('cargo', 20);                    // presidente, vicepresidente, secretario, tesorero
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('periodo_inicio');
            $table->date('periodo_fin');
            $table->string('acta', 80);
            $table->date('cerrado_en')->nullable();
            $table->timestamps();
            $table->index(['condominio_id', 'cargo']);
        });

        DB::statement('CREATE UNIQUE INDEX cargos_directiva_cargo_abierto ON cargos_directiva (condominio_id, cargo) WHERE cerrado_en IS NULL');
        DB::statement('CREATE UNIQUE INDEX cargos_directiva_persona_abierta ON cargos_directiva (condominio_id, persona_id) WHERE cerrado_en IS NULL');

        RowLevelSecurity::enable('cargos_directiva');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('cargos_directiva');
        Schema::dropIfExists('cargos_directiva');
    }
};
