<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parroquias urbanas y rurales (código INEC de seis dígitos). Sin RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parroquias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canton_id')->constrained('cantones')->cascadeOnDelete();
            $table->char('codigo', 6)->unique();
            $table->string('nombre', 80);
            $table->timestamps();
            $table->index(['canton_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parroquias');
    }
};
