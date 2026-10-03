<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Datos del asistente de alta: plan y valor por unidad (lo que cobra la plataforma),
 * ubicación (códigos INEC y coordenadas), contacto y fin del periodo de prueba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('condominios', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('total_unidades')->constrained('planes');
            $table->decimal('valor_unidad', 12, 2)->nullable()->after('plan_id');
            $table->char('provincia_codigo', 2)->nullable();
            $table->char('canton_codigo', 4)->nullable();
            $table->char('parroquia_codigo', 6)->nullable();
            $table->string('direccion', 200)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('email_contacto', 160)->nullable();
            $table->decimal('latitud', 9, 6)->nullable();
            $table->decimal('longitud', 9, 6)->nullable();
            $table->date('prueba_hasta')->nullable();
            $table->foreign('provincia_codigo')->references('codigo')->on('ubicacion_provincias');
            $table->foreign('canton_codigo')->references('codigo')->on('ubicacion_cantones');
            $table->foreign('parroquia_codigo')->references('codigo')->on('ubicacion_parroquias');
        });

        // Un RUC no se repite en la plataforma (puede faltar en condominios antiguos)
        DB::statement('CREATE UNIQUE INDEX condominios_ruc_unico ON condominios (ruc) WHERE ruc IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS condominios_ruc_unico');

        Schema::table('condominios', function (Blueprint $table) {
            $table->dropForeign(['provincia_codigo']);
            $table->dropForeign(['canton_codigo']);
            $table->dropForeign(['parroquia_codigo']);
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn([
                'valor_unidad', 'provincia_codigo', 'canton_codigo', 'parroquia_codigo',
                'direccion', 'telefono', 'email_contacto', 'latitud', 'longitud', 'prueba_hasta',
            ]);
        });
    }
};
