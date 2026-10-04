<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unidades del condominio: departamento, casa, local, parqueadero o bodega.
 * El estado (ocupada, arrendada, vacía) no se guarda: se calcula con los ocupantes.
 * Los montos solo se usan según el método de cobro del condominio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('bloque_id')->nullable()->constrained('bloques')->nullOnDelete();
            $table->string('codigo', 20);
            $table->string('tipo', 20);                                // departamento, casa, local, parqueadero, bodega
            $table->smallInteger('piso')->nullable();
            $table->decimal('area_m2', 8, 2);
            $table->decimal('alicuota', 7, 4)->nullable();             // porcentaje de la declaratoria (0,6200 %)
            $table->decimal('cuota_mensual', 12, 2)->nullable();       // método de cobro "unidad"
            $table->decimal('valor_personalizado', 12, 2)->nullable(); // excepción aprobada (métodos "tipo" y "alicuota")
            $table->string('responsable_pago', 12)->default('propietario');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['condominio_id', 'bloque_id']);
            $table->index(['condominio_id', 'tipo']);
        });

        // El código no se repite en el condominio entre las unidades vigentes
        DB::statement('CREATE UNIQUE INDEX unidades_codigo_unico ON unidades (condominio_id, codigo) WHERE deleted_at IS NULL');

        RowLevelSecurity::enable('unidades');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('unidades');
        Schema::dropIfExists('unidades');
    }
};
