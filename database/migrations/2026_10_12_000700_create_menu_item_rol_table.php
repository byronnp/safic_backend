<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué ítems del menú ve cada perfil (rol global). Lo administra el super admin.
 * Un ítem asignado solo se muestra si el usuario además tiene su permiso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_rol', function (Blueprint $table) {
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['menu_item_id', 'role_id']);
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_rol');
    }
};
