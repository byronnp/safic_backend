<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuota mensual por tipo de unidad (método de cobro "tipo").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobro_valores_tipo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('tipo_unidad', 20);   // departamento, casa, local, parqueadero, bodega
            $table->decimal('valor', 12, 2);
            $table->timestamps();
            $table->unique(['condominio_id', 'tipo_unidad']);
        });

        RowLevelSecurity::enable('cobro_valores_tipo');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('cobro_valores_tipo');
        Schema::dropIfExists('cobro_valores_tipo');
    }
};
