<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cédula y celular del usuario (el asistente los pide para el administrador).
 * Datos personales: se enmascaran para roles sin residentes.ver_datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cedula', 10)->nullable()->after('name');
            $table->string('celular', 10)->nullable()->after('email');
        });

        DB::statement('CREATE UNIQUE INDEX users_cedula_unica ON users (cedula) WHERE cedula IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_cedula_unica');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['cedula', 'celular']);
        });
    }
};
