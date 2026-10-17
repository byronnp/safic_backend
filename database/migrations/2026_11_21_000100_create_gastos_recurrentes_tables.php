<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos recurrentes (guardianía, limpieza, luz…). Cada mes, a partir de su día, se crea una
 * `ocurrencia`: lo que falta es adjuntar la factura real, que se vincula a la ocurrencia. Un
 * recurrente sin monto es de monto variable (luz, agua): el monto sale de la factura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gastos_recurrentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('nombre', 80);
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->string('categoria', 60)->nullable();
            $table->decimal('monto', 12, 2)->nullable();                  // null = variable
            $table->unsignedSmallInteger('dia_mes');                      // 1–28
            $table->boolean('activo')->default(true);
            $table->boolean('aprobacion_automatica')->default(false);      // nivel 1 si la factura coincide con el monto
            $table->timestamps();

            $table->index(['condominio_id', 'activo']);
        });
        DB::statement('ALTER TABLE gastos_recurrentes ADD CONSTRAINT gastos_recurrentes_dia_valido CHECK (dia_mes BETWEEN 1 AND 28)');
        DB::statement('ALTER TABLE gastos_recurrentes ADD CONSTRAINT gastos_recurrentes_monto_positivo CHECK (monto IS NULL OR monto > 0)');

        Schema::create('recurrente_ocurrencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('gasto_recurrente_id')->constrained('gastos_recurrentes')->cascadeOnDelete();
            $table->date('periodo');                                      // siempre el día 1
            $table->date('fecha_prevista');
            $table->foreignId('gasto_id')->nullable()->constrained('gastos')->nullOnDelete();
            $table->boolean('aprobada_automaticamente')->default(false);
            $table->timestamp('avisada_en')->nullable();
            $table->timestamps();

            $table->unique(['gasto_recurrente_id', 'periodo']);
        });
        // Una factura cubre una sola ocurrencia
        DB::statement('CREATE UNIQUE INDEX recurrente_ocurrencias_gasto_unico ON recurrente_ocurrencias (gasto_id) WHERE gasto_id IS NOT NULL');

        RowLevelSecurity::enable('gastos_recurrentes');
        RowLevelSecurity::enable('recurrente_ocurrencias');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('recurrente_ocurrencias');
        RowLevelSecurity::disable('gastos_recurrentes');
        Schema::dropIfExists('recurrente_ocurrencias');
        Schema::dropIfExists('gastos_recurrentes');
    }
};
