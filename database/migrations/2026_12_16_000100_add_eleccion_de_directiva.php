<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 · Elección de directiva. Cada punto de elección tiene candidatos por cargo; cada cargo se vota en su propia votación (una
 * persona por cargo) y cada unidad vota por un candidato o en blanco. Al cerrar el punto, los ganadores se nombran en sus cargos
 * (`cargos_directiva`) con la asamblea como respaldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidatos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('asamblea_id')->constrained('asambleas')->cascadeOnDelete();
            $table->foreignId('punto_id')->constrained('asamblea_puntos')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->string('cargo', 20);                                    // presidente, vicepresidente, secretario, tesorero
            $table->foreignId('postulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['punto_id', 'cargo', 'persona_id']);
        });

        Schema::table('votaciones', function (Blueprint $table) {
            $table->string('cargo', 20)->nullable();                        // solo en elecciones: el cargo que se vota
            $table->unsignedBigInteger('ganador_candidato_id')->nullable(); // quien ganó el cargo, si alguien alcanzó la mayoría
        });
        // Un punto de votación tiene una votación; uno de elección, una por cargo
        Schema::table('votaciones', function (Blueprint $table) {
            $table->dropUnique('votaciones_punto_id_unique');
        });
        DB::statement('CREATE UNIQUE INDEX votaciones_punto_unico ON votaciones (punto_id) WHERE cargo IS NULL');
        DB::statement('CREATE UNIQUE INDEX votaciones_punto_cargo_unico ON votaciones (punto_id, cargo) WHERE cargo IS NOT NULL');

        Schema::table('votos', function (Blueprint $table) {
            $table->foreignId('candidato_id')->nullable()->constrained('candidatos')->nullOnDelete();   // opcion «candidato»; «blanco» no lleva
        });

        Schema::table('asamblea_puntos', function (Blueprint $table) {
            $table->timestampTz('eleccion_cerrada_en')->nullable();
            $table->jsonb('nombramientos')->nullable();                     // lo que quedó al cerrar la elección (va en el acta)
        });

        RowLevelSecurity::enable('candidatos');
    }

    public function down(): void
    {
        Schema::table('asamblea_puntos', function (Blueprint $table) {
            $table->dropColumn(['eleccion_cerrada_en', 'nombramientos']);
        });
        Schema::table('votos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('candidato_id');
        });
        DB::statement('DROP INDEX IF EXISTS votaciones_punto_unico');
        DB::statement('DROP INDEX IF EXISTS votaciones_punto_cargo_unico');
        Schema::table('votaciones', function (Blueprint $table) {
            $table->dropColumn(['cargo', 'ganador_candidato_id']);
            $table->unique('punto_id');
        });
        Schema::dropIfExists('candidatos');
    }
};
