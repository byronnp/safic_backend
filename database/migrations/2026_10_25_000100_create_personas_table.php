<?php

use App\Core\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personas del condominio (quien vive o es dueño). No toda persona tiene cuenta.
 * Documento y teléfono se guardan cifrados (LOPDP); documento_hash (HMAC) permite
 * buscar por documento exacto y exigir que no se repita en el condominio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('tipo_documento', 10);          // cedula, ruc, pasaporte
            $table->text('documento');                     // cifrado
            $table->char('documento_hash', 64);            // HMAC-SHA256 del tipo y documento normalizados
            $table->string('nombres', 80);
            $table->string('apellidos', 80);
            $table->text('telefono')->nullable();          // cifrado
            $table->string('email', 160)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['condominio_id', 'apellidos', 'nombres']);
        });

        // El documento no se repite en el condominio entre las personas vigentes
        DB::statement('CREATE UNIQUE INDEX personas_documento_unico ON personas (condominio_id, documento_hash) WHERE deleted_at IS NULL');

        RowLevelSecurity::enable('personas');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('personas');
        Schema::dropIfExists('personas');
    }
};
