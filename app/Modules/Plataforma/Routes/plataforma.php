<?php

use App\Core\Permissions\Permiso;
use App\Modules\Plataforma\Http\Controllers\CatalogoController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Plataforma en el panel del super admin. routes/api.php las carga
// dentro del grupo auth:api + plataforma con prefijo /plataforma: el permiso se mira
// en el equipo 0 (roles de plataforma). Cada ruta exige su permiso.

Route::get('planes', [CatalogoController::class, 'planes'])
    ->middleware('permission:'.Permiso::PlataformaCondominios->value);

Route::get('catalogos', [CatalogoController::class, 'catalogos'])
    ->middleware('permission:'.Permiso::PlataformaCondominios->value);

Route::get('ubicaciones', [CatalogoController::class, 'ubicaciones'])
    ->middleware('permission:'.Permiso::PlataformaCondominios->value);
