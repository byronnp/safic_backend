<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos de las amenidades de un condominio. El archivo vive en S3 (bucket privado) y aquí
 * solo queda su ruta y el orden: la de orden 1 es la portada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenidad_fotos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('amenidad_id')->constrained('condominio_amenidades')->cascadeOnDelete();
            $table->string('ruta', 255);
            $table->unsignedSmallInteger('orden');
            $table->timestamps();

            $table->index(['condominio_id', 'amenidad_id', 'orden']);
        });

        RowLevelSecurity::enable('amenidad_fotos');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('amenidad_fotos');
        Schema::dropIfExists('amenidad_fotos');
    }
};
