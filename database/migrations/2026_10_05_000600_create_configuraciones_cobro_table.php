<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo cobra el condominio las cuotas a sus residentes. Una fila por condominio.
 * metodo: general (todas pagan cuota_general), tipo (valores en cobro_valores_tipo),
 * alicuota (presupuesto_mensual repartido por alícuota) o unidad (cada unidad su cuota).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones_cobro', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('metodo', 10);
            $table->decimal('cuota_general', 12, 2)->nullable();
            $table->decimal('presupuesto_mensual', 12, 2)->nullable();
            $table->unsignedTinyInteger('dia_vencimiento');   // 1–28; 0 = último día del mes
            $table->date('aplica_desde');                     // primer día del mes de la primera cuota
            $table->timestamps();
            $table->unique('condominio_id');
        });

        DB::statement("ALTER TABLE configuraciones_cobro ADD CONSTRAINT configuraciones_cobro_metodo_check CHECK (metodo IN ('general', 'tipo', 'alicuota', 'unidad'))");
        DB::statement("ALTER TABLE configuraciones_cobro ADD CONSTRAINT configuraciones_cobro_general_check CHECK (metodo <> 'general' OR cuota_general > 0)");
        DB::statement("ALTER TABLE configuraciones_cobro ADD CONSTRAINT configuraciones_cobro_alicuota_check CHECK (metodo <> 'alicuota' OR presupuesto_mensual > 0)");

        RowLevelSecurity::enable('configuraciones_cobro');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('configuraciones_cobro');
        Schema::dropIfExists('configuraciones_cobro');
    }
};
