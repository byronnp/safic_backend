<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 · Poderes (Art. 40): el propietario que no puede asistir sube el poder notarial o la carta firmada; la administración lo
 * valida y la unidad queda asignada al representante. Una unidad tiene a lo más un poder vigente (pendiente o validado) por asamblea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asamblea_poderes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('asamblea_id')->constrained('asambleas')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->foreignId('otorgante_persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->string('tipo', 10);                                     // notarial o carta
            $table->string('representante_nombre', 120);
            $table->string('representante_tipo_documento', 10);
            $table->text('representante_documento');                        // cifrado en reposo (datos personales)
            $table->foreignId('representante_persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->string('documento_ruta', 255);
            $table->string('documento_nombre', 120);
            $table->string('estado', 10)->default('pendiente');             // pendiente, validado, rechazado, revocado
            $table->string('motivo_rechazo', 300)->nullable();
            $table->foreignId('cargado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validado_en')->nullable();
            $table->timestampTz('revocado_en')->nullable();
            $table->timestamps();

            $table->index(['asamblea_id', 'estado']);
        });

        // Un solo poder vigente por unidad y asamblea
        DB::statement("CREATE UNIQUE INDEX asamblea_poderes_vigente_unico ON asamblea_poderes (asamblea_id, unidad_id) WHERE estado IN ('pendiente', 'validado')");

        RowLevelSecurity::enable('asamblea_poderes');
    }

    public function down(): void
    {
        Schema::dropIfExists('asamblea_poderes');
    }
};
