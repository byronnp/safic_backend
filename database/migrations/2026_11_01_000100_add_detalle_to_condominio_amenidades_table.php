<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pantalla Configuración › Amenidades: la categoría (también para las amenidades propias,
 * que no tienen catálogo), dónde está la amenidad y hasta cuándo está en mantenimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('condominio_amenidades', function (Blueprint $table) {
            $table->string('categoria', 12)->nullable()->after('nombre');   // recreacion, deporte, social, servicios, seguridad
            $table->string('ubicacion', 80)->nullable()->after('cantidad');
            $table->date('mantenimiento_hasta')->nullable()->after('activa');
        });

        // Las amenidades que ya vienen del catálogo heredan su categoría
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE condominio_amenidades ca SET categoria = c.categoria FROM amenidades_catalogo c WHERE ca.amenidad_catalogo_id = c.id AND ca.categoria IS NULL');
        }
    }

    public function down(): void
    {
        Schema::table('condominio_amenidades', function (Blueprint $table) {
            $table->dropColumn(['categoria', 'ubicacion', 'mantenimiento_hasta']);
        });
    }
};
