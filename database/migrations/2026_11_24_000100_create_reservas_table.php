<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reservas de áreas comunes. Dos reservas vivas (pendiente o confirmada) de la misma área no pueden
 * cruzarse: lo garantiza la base con una restricción de exclusión, no solo la aplicación.
 * Fecha y horas son locales del condominio. Costo y garantía son una copia de las reglas al reservar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('amenidad_id')->constrained('condominio_amenidades')->restrictOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('solicitante_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('fecha');
            $table->time('inicio');
            $table->time('fin');
            $table->string('estado', 12);                                  // pendiente, confirmada, rechazada, cancelada, vencida
            $table->decimal('costo', 12, 2)->default(0);
            $table->decimal('garantia', 12, 2)->default(0);
            $table->timestampTz('reglamento_aceptado_en');
            $table->timestampTz('cancelada_en')->nullable();
            $table->string('motivo_cancelacion', 200)->nullable();
            $table->timestamps();

            $table->index(['condominio_id', 'fecha']);
            $table->index(['unidad_id', 'fecha']);
        });
        DB::statement('ALTER TABLE reservas ADD CONSTRAINT reservas_horas CHECK (inicio < fin)');
        DB::statement("ALTER TABLE reservas ADD CONSTRAINT reservas_sin_cruce EXCLUDE USING gist (amenidad_id WITH =, tsrange((fecha + inicio), (fecha + fin)) WITH &&) WHERE (estado IN ('pendiente', 'confirmada'))");

        RowLevelSecurity::enable('reservas');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('reservas');
        Schema::dropIfExists('reservas');
    }
};
