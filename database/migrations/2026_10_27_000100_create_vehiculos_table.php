<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vehículos de cada unidad. La placa se guarda normalizada (PBA-1234) y no se
 * repite en el condominio: la garita la busca para identificar la unidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehiculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();
            $table->string('placa', 10);
            $table->string('tipo', 10);            // auto, moto
            $table->string('marca', 40)->nullable();
            $table->string('modelo', 40)->nullable();
            $table->string('color', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['condominio_id', 'unidad_id']);
        });

        DB::statement('CREATE UNIQUE INDEX vehiculos_placa_unica ON vehiculos (condominio_id, placa) WHERE deleted_at IS NULL');

        RowLevelSecurity::enable('vehiculos');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('vehiculos');
        Schema::dropIfExists('vehiculos');
    }
};
