<?php

use App\Core\Permissions\Permiso;
use App\Modules\Plataforma\Http\Controllers\CatalogoController;
use Illuminate\Support\Facades\Route;

// Rutas del panel de plataforma. Se cargan dentro del grupo auth:api + plataforma
// con prefijo /plataforma: el permiso se mira en el equipo 0 (roles de plataforma).

Route::get('planes', [CatalogoController::class, 'planes'])
    ->middleware('permission:'.Permiso::PlataformaCondominios->value);

Route::get('catalogos', [CatalogoController::class, 'catalogos'])
    ->middleware('permission:'.Permiso::PlataformaCondominios->value);

Route::get('ubicaciones', [CatalogoController::class, 'ubicaciones'])
    ->middleware('permission:'.Permiso::PlataformaCondominios->value);
