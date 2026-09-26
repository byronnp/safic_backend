<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Primera tabla de condominio. Patrón obligatorio para toda tabla con datos de
 * un condominio: columna condominio_id + índices que empiezan por ella + RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('nombre', 60);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
            $table->unique(['condominio_id', 'nombre']);
        });

        RowLevelSecurity::enable('bloques');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('bloques');
        Schema::dropIfExists('bloques');
    }
};
