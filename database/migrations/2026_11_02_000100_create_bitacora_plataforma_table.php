<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de cambios de las tablas de plataforma (condominios, membresías, catálogo de
 * amenidades, menú, roles y permisos). Las tablas de cada condominio usan `audits`; estas
 * no tienen condominio_id ni RLS, así que van aparte. Solo inserción: la aplicación no
 * puede modificar ni borrar filas. El nombre del actor se guarda tal cual estaba, por si
 * la cuenta cambia o se elimina; `condominio_id` es informativo (sin llave foránea).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora_plataforma', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_nombre', 120)->nullable();
            $table->string('evento', 20);                 // creado, actualizado, eliminado
            $table->string('entidad', 40);                // menu, rol, catalogo_amenidad, condominio, membresia
            $table->string('entidad_id', 60)->nullable(); // id o clave del registro
            $table->string('etiqueta', 160)->nullable();  // nombre legible del registro
            $table->unsignedBigInteger('condominio_id')->nullable();
            $table->json('valores_anteriores')->nullable();
            $table->json('valores_nuevos')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('url', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['entidad', 'entidad_id']);
            $table->index('user_id');
            $table->index('condominio_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON bitacora_plataforma FROM safic_app');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora_plataforma');
    }
};
