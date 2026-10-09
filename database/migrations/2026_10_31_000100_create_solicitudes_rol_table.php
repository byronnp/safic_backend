<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos de un rol nuevo que un condominio le hace a la plataforma (los roles los
 * define el super admin). Queda el registro aunque el correo de aviso falle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_rol', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre', 60);
            $table->string('descripcion', 500);
            $table->string('estado', 20)->default('pendiente');   // pendiente, atendida, rechazada
            $table->timestamps();
            $table->index(['condominio_id', 'estado']);
        });

        RowLevelSecurity::enable('solicitudes_rol');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('solicitudes_rol');
        Schema::dropIfExists('solicitudes_rol');
    }
};
