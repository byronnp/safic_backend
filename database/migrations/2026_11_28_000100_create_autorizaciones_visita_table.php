<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Autorizaciones de visita: el residente anuncia a quién espera (una vez o de forma recurrente) y obtiene un QR.
 * El QR es el `token` (opaco, sin datos personales): la garita lo busca para validar el ingreso. La cédula del
 * visitante es opcional y va cifrada (LOPDP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorizaciones_visita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->string('visitante_nombre', 120);
            $table->text('visitante_documento')->nullable();               // cifrado
            $table->string('placa', 12)->nullable();
            $table->string('tipo', 12);                                    // unica (un solo ingreso), recurrente
            $table->date('fecha_desde');
            $table->date('fecha_hasta');
            $table->time('hora_desde');
            $table->time('hora_hasta');
            $table->json('dias_semana')->nullable();                       // recurrente: 1 = lunes … 7 = domingo
            $table->unsignedSmallInteger('usos')->default(0);              // ingresos registrados por la garita
            $table->timestampTz('revocada_en')->nullable();
            $table->timestamps();

            $table->index(['condominio_id', 'unidad_id']);
            $table->index(['condominio_id', 'fecha_hasta']);
        });
        DB::statement('ALTER TABLE autorizaciones_visita ADD CONSTRAINT autorizaciones_visita_fechas CHECK (fecha_desde <= fecha_hasta)');
        DB::statement('ALTER TABLE autorizaciones_visita ADD CONSTRAINT autorizaciones_visita_horas CHECK (hora_desde < hora_hasta)');

        RowLevelSecurity::enable('autorizaciones_visita');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('autorizaciones_visita');
        Schema::dropIfExists('autorizaciones_visita');
    }
};
