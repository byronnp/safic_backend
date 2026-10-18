<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Garita sin conexión: el teléfono del guardia registra ingresos y salidas con una copia local de los QR del día y los envía al volver la
 * red. Cada ingreso trae un `uuid` que genera el teléfono, para que reenviarlo no lo duplique. Si al sincronizar el QR ya no servía (otro
 * guardia lo usó antes, lo revocaron), el ingreso se guarda igual —ya ocurrió— con una `nota` que lo explica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accesos_garita', function (Blueprint $table) {
            $table->uuid('uuid')->nullable();
            $table->boolean('sin_conexion')->default(false);
            $table->timestampTz('sincronizado_en')->nullable();
            $table->string('nota', 200)->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX accesos_garita_uuid_unico ON accesos_garita (condominio_id, uuid) WHERE uuid IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS accesos_garita_uuid_unico');
        Schema::table('accesos_garita', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'sin_conexion', 'sincronizado_en', 'nota']);
        });
    }
};
