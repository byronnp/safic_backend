<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas de uso de un área común (una amenidad reservable): horario, duración, anticipación,
 * capacidad, costos y reglamento. La aprobación del administrador y si se restringe por mora
 * viven en la amenidad (`requiere_aprobacion`, `esencial`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglas_area', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('amenidad_id')->unique()->constrained('condominio_amenidades')->cascadeOnDelete();
            $table->time('abre')->default('08:00');
            $table->time('cierra')->default('22:00');
            $table->unsignedSmallInteger('duracion_min_horas')->default(1);
            $table->unsignedSmallInteger('duracion_max_horas')->default(4);
            $table->unsignedSmallInteger('anticipacion_min_horas')->default(0);
            $table->unsignedSmallInteger('anticipacion_max_dias')->default(30);
            $table->unsignedSmallInteger('capacidad')->nullable();
            $table->unsignedSmallInteger('reservas_por_unidad_mes')->nullable();
            $table->json('dias_semana');                                   // 1 = lunes … 7 = domingo
            $table->decimal('costo', 12, 2)->default(0);
            $table->decimal('garantia', 12, 2)->default(0);
            $table->text('reglamento')->nullable();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE reglas_area ADD CONSTRAINT reglas_area_horario CHECK (abre < cierra)');
        DB::statement('ALTER TABLE reglas_area ADD CONSTRAINT reglas_area_duracion CHECK (duracion_min_horas >= 1 AND duracion_min_horas <= duracion_max_horas)');
        DB::statement('ALTER TABLE reglas_area ADD CONSTRAINT reglas_area_montos CHECK (costo >= 0 AND garantia >= 0)');

        RowLevelSecurity::enable('reglas_area');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('reglas_area');
        Schema::dropIfExists('reglas_area');
    }
};
