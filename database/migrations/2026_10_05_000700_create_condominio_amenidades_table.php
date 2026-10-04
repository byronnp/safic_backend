<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amenidades de un condominio. Copian los valores del catálogo al elegirlas para que
 * el condominio los ajuste sin tocar el catálogo global (S3 agrega amenidades propias).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condominio_amenidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('amenidad_catalogo_id')->nullable()->constrained('amenidades_catalogo')->nullOnDelete();
            $table->string('nombre', 80);
            $table->unsignedSmallInteger('cantidad')->default(1);
            $table->boolean('reservable')->default(false);
            $table->boolean('esencial')->default(false);
            $table->boolean('requiere_aprobacion')->default(false);
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->unique(['condominio_id', 'nombre']);
        });

        RowLevelSecurity::enable('condominio_amenidades');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('condominio_amenidades');
        Schema::dropIfExists('condominio_amenidades');
    }
};
