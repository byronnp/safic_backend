<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La cuenta con la que una persona entra al sistema (app del residente). Una persona tiene
 * una sola cuenta y una cuenta es una sola persona en cada condominio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('condominio_id')->constrained('users')->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX personas_cuenta_unica ON personas (condominio_id, user_id) WHERE user_id IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS personas_cuenta_unica');
        Schema::table('personas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
