<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asambleas: la convocatoria. Al convocar se publica un anuncio con confirmación de lectura (Fase 4): su constancia por unidad
 * es el respaldo legal de que se convocó con anticipación. Los recordatorios (3 días y 2 horas antes) se registran para no repetirlos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asambleas', function (Blueprint $table) {
            $table->foreignId('anuncio_id')->nullable()->constrained('anuncios')->nullOnDelete();
            $table->timestampTz('recordada_3d_en')->nullable();
            $table->timestampTz('recordada_2h_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('asambleas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anuncio_id');
            $table->dropColumn(['recordada_3d_en', 'recordada_2h_en']);
        });
    }
};
