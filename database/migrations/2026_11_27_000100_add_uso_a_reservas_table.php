<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas: el uso del día. El guardia registra el ingreso (`en_uso`) y recibe el área (`finalizada`), con las novedades
 * o daños si los hay. Una reserva en uso sigue ocupando su horario, así que la restricción anti-cruce la incluye.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->timestampTz('ingreso_en')->nullable();
            $table->foreignId('ingreso_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('entrega_en')->nullable();
            $table->foreignId('entrega_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('danos', 500)->nullable();
        });

        DB::statement('ALTER TABLE reservas DROP CONSTRAINT reservas_sin_cruce');
        DB::statement("ALTER TABLE reservas ADD CONSTRAINT reservas_sin_cruce EXCLUDE USING gist (amenidad_id WITH =, tsrange((fecha + inicio), (fecha + fin)) WITH &&) WHERE (estado IN ('pendiente', 'confirmada', 'en_uso'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reservas DROP CONSTRAINT reservas_sin_cruce');
        DB::statement("ALTER TABLE reservas ADD CONSTRAINT reservas_sin_cruce EXCLUDE USING gist (amenidad_id WITH =, tsrange((fecha + inicio), (fecha + fin)) WITH &&) WHERE (estado IN ('pendiente', 'confirmada'))");
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingreso_por');
            $table->dropConstrainedForeignId('entrega_por');
            $table->dropColumn(['ingreso_en', 'entrega_en', 'danos']);
        });
    }
};
