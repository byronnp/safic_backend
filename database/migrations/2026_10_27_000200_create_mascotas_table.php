<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mascotas de cada unidad. La foto llega con el almacenamiento de archivos (paso 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mascotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->string('nombre', 40);
            $table->string('especie', 10);         // perro, gato, ave, otro
            $table->string('raza', 40)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['condominio_id', 'unidad_id']);
        });

        RowLevelSecurity::enable('mascotas');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('mascotas');
        Schema::dropIfExists('mascotas');
    }
};
