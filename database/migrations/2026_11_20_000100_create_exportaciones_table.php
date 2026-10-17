<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada archivo que se exporta (Excel de un reporte) queda aquí con un código único: quién, qué
 * reporte, con qué parámetros y cuántas filas. Es la bitácora de exportaciones del contador y
 * lo que cuenta el límite diario por persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('codigo', 12);                                  // EXP-7Q2K
            $table->string('reporte', 30);
            $table->string('titulo', 120);                                 // «Ingresos vs. gastos · sep 2026»
            $table->jsonb('parametros')->nullable();
            $table->string('formato', 5)->default('xlsx');
            $table->unsignedInteger('filas')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_nombre', 120);
            $table->string('user_rol', 30)->nullable();
            $table->timestamps();

            $table->unique(['condominio_id', 'codigo']);
            $table->index(['condominio_id', 'user_id', 'created_at']);
        });

        RowLevelSecurity::enable('exportaciones');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('exportaciones');
        Schema::dropIfExists('exportaciones');
    }
};
