<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El catálogo de acceso vive en la base: cada permiso y cada rol lleva aquí su etiqueta, su ámbito y sus reglas, en vez de estar
 * escritos en el código. Los seeders (database/data/acceso.php y menu.php) los cargan; un permiso, rol o pantalla nueva es un dato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('etiqueta', 120)->nullable();                     // qué permite, en palabras de quien administra
            $table->string('grupo', 40)->nullable();                         // cómo se agrupa en la pantalla Roles
            $table->string('ambito', 12)->default('condominio');             // condominio o plataforma
            $table->boolean('administrativo')->default(false);               // cuenta para el límite de usuarios administrativos
            $table->boolean('escritura')->default(false);                    // modifica datos (un rol de solo lectura no lo recibe)
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('etiqueta', 80)->nullable();
            $table->string('ambito', 12)->default('condominio');
            $table->boolean('es_cargo')->default(false);                     // cargo de la directiva (se asigna por su tabla)
            $table->boolean('cuenta_cupo')->nullable();                      // nulo: se deduce de si tiene permisos administrativos
            $table->boolean('asignable')->default(false);                    // el administrador lo asigna directamente a un usuario
            $table->boolean('requiere_vigencia')->default(false);            // acceso con fecha de vencimiento obligatoria
            $table->boolean('exige_doble_factor')->default(false);           // no puede trabajar sin la verificación en dos pasos
            $table->boolean('solo_lectura')->default(false);                 // nunca recibe permisos de escritura
            $table->boolean('sin_administrativos')->default(false);          // nunca recibe permisos administrativos
            $table->boolean('sistema')->default(false);                      // el código lo nombra: no se borra ni se renombra
            $table->unsignedSmallInteger('orden')->default(100);
        });

        // Permisos que un rol no puede perder (p. ej. el administrador sin «gestionar usuarios» se quedaría sin poder administrar)
        Schema::create('rol_permisos_obligatorios', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        // Roles y permisos que ya existían sin datos (creados por el super admin): una etiqueta legible y el ámbito que tenían de hecho
        DB::statement("UPDATE roles SET etiqueta = initcap(replace(name, '_', ' ')) WHERE etiqueta IS NULL");
        DB::statement('UPDATE permissions SET etiqueta = name WHERE etiqueta IS NULL');
        DB::statement("UPDATE permissions SET ambito = 'plataforma' WHERE name LIKE 'plataforma.%'");
        DB::statement("UPDATE roles SET ambito = 'plataforma' WHERE id IN (SELECT rhp.role_id FROM role_has_permissions rhp JOIN permissions p ON p.id = rhp.permission_id WHERE p.name LIKE 'plataforma.%')");
    }

    public function down(): void
    {
        Schema::dropIfExists('rol_permisos_obligatorios');
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['etiqueta', 'ambito', 'es_cargo', 'cuenta_cupo', 'asignable', 'requiere_vigencia', 'exige_doble_factor', 'solo_lectura', 'sin_administrativos', 'sistema', 'orden']);
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['etiqueta', 'grupo', 'ambito', 'administrativo', 'escritura']);
        });
    }
};
