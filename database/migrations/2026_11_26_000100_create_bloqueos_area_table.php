<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueos de un área común: un día completo (sin horas) o unas horas de un día, con su motivo
 * (mantenimiento de la parrilla, evento del condominio…). No admiten reservas nuevas encima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloqueos_area', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('amenidad_id')->constrained('condominio_amenidades')->cascadeOnDelete();
            $table->date('fecha');
            $table->time('inicio')->nullable();                            // sin horas = todo el día
            $table->time('fin')->nullable();
            $table->string('motivo', 120);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['condominio_id', 'fecha']);
            $table->index(['amenidad_id', 'fecha']);
        });
        DB::statement('ALTER TABLE bloqueos_area ADD CONSTRAINT bloqueos_area_horas CHECK ((inicio IS NULL AND fin IS NULL) OR (inicio IS NOT NULL AND fin IS NOT NULL AND inicio < fin))');

        RowLevelSecurity::enable('bloqueos_area');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('bloqueos_area');
        Schema::dropIfExists('bloqueos_area');
    }
};
