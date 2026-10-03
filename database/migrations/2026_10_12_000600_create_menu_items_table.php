<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menú del sistema: catálogo global que administra el super admin.
 * Ámbito "condominio" (administración y directiva) o "plataforma" (panel del
 * super admin). Ocultar un ítem no es seguridad: cada ruta exige su permiso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 60)->unique();               // id estable que usa el frontend
            $table->foreignId('padre_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->string('ambito', 20);                        // condominio, plataforma
            $table->string('etiqueta', 60);
            $table->string('icono', 60);                         // obligatorio: sym_r_*
            $table->string('ruta', 80)->nullable();              // nombre de la ruta del frontend
            $table->string('permiso', 60)->nullable();           // valor de App\Core\Permissions\Permiso
            $table->boolean('seccion')->default(false);          // grupo siempre desplegado
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['ambito', 'orden']);
        });

        DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_ambito_valido CHECK (ambito IN ('condominio', 'plataforma'))");
        DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_icono_obligatorio CHECK (icono <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
