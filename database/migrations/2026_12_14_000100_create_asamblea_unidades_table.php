<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 · Instalación. Al instalar la asamblea se congela la foto del condominio: alícuotas, propietarios y marca de mora de cada
 * unidad del padrón. Así un pago o un cambio de propietario a mitad de la sesión no altera el quórum ni los resultados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asamblea_unidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('asamblea_id')->constrained('asambleas')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->string('codigo', 30);                                   // el código de la unidad al instalar
            $table->decimal('alicuota', 7, 4);
            $table->boolean('en_mora');
            $table->boolean('puede_votar');                                 // sin mora (Art. 39 y Decreto 462)
            $table->foreignId('propietario_persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->jsonb('propietarios');                                  // [{persona_id, nombre}], el principal primero
            $table->timestamps();

            $table->unique(['asamblea_id', 'unidad_id']);
        });

        Schema::table('asambleas', function (Blueprint $table) {
            $table->foreignId('instalada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('en_subrogacion')->default(false);              // el vicepresidente instaló por ausencia del presidente
            $table->boolean('mora_cuenta_para_quorum')->nullable();         // la regla vigente al instalar
            $table->decimal('quorum_alicuota_presente', 8, 4)->nullable();  // lo que había al instalar (va en el acta)
            $table->decimal('quorum_alicuota_total', 8, 4)->nullable();
        });

        RowLevelSecurity::enable('asamblea_unidades');
    }

    public function down(): void
    {
        Schema::table('asambleas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('instalada_por');
            $table->dropColumn(['en_subrogacion', 'mora_cuenta_para_quorum', 'quorum_alicuota_presente', 'quorum_alicuota_total']);
        });
        Schema::dropIfExists('asamblea_unidades');
    }
};
