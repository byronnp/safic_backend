<?php

use Illuminate\Support\Facades\Route;

// SAFIC es solo API. La salud del servicio está en /up.
Route::get('/', fn () => response()->json(['app' => 'SAFIC API', 'docs' => '/api/v1']));
