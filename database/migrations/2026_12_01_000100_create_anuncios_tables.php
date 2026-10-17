<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anuncios de la administración. Al publicar se fija la lista de unidades que lo reciben (todo el condominio, un bloque o unidades
 * concretas): por cada una se registra si ya lo leyó y, cuando se pide, si confirmó la lectura (constancia de convocatorias).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anuncios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('titulo', 120);
            $table->text('mensaje');
            $table->string('destino_tipo', 10);                            // todos, bloque, unidades
            $table->string('destino_texto', 120);                          // «Todo el condominio», «Torre C», «3 unidades»
            $table->boolean('fijado')->default(false);
            $table->boolean('requiere_confirmacion')->default(false);
            $table->date('expira_en')->nullable();
            $table->string('adjunto_ruta', 255)->nullable();
            $table->string('adjunto_nombre', 120)->nullable();
            $table->foreignId('publicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['condominio_id', 'created_at']);
        });

        Schema::create('anuncio_destinatarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('anuncio_id')->constrained('anuncios')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->timestampTz('leido_en')->nullable();
            $table->foreignId('leido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmado_en')->nullable();
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['anuncio_id', 'unidad_id']);
            $table->index(['unidad_id', 'anuncio_id']);
        });

        RowLevelSecurity::enable('anuncios');
        RowLevelSecurity::enable('anuncio_destinatarios');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('anuncio_destinatarios');
        RowLevelSecurity::disable('anuncios');
        Schema::dropIfExists('anuncio_destinatarios');
        Schema::dropIfExists('anuncios');
    }
};
