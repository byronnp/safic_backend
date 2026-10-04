<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de cambios (laravel-auditing): quién cambió qué, con valores
 * anteriores y nuevos. Es un registro de solo inserción: la aplicación no puede
 * modificar ni borrar filas (la retención de 5 años la ejecuta el dueño de la tabla).
 * Con RLS, cada condominio solo ve su propia auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios');
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event');
            $table->morphs('auditable');
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();
            $table->timestamps();

            $table->index(['condominio_id', 'created_at']);
            $table->index(['user_id', 'user_type']);
        });

        RowLevelSecurity::enable('audits');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON audits FROM safic_app');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
