<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Al inactivar (suspender) un condominio se guarda el estado que tenía, para devolverlo igual al
 * reactivarlo (prueba, activo o solo lectura).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('condominios', function (Blueprint $table) {
            $table->string('estado_antes_suspension', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('condominios', function (Blueprint $table) {
            $table->dropColumn('estado_antes_suspension');
        });
    }
};
