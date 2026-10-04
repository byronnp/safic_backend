<?php

use App\Core\Permissions\Permiso;
use App\Modules\Plataforma\Http\Controllers\CatalogoController;
use App\Modules\Plataforma\Http\Controllers\CondominioController;
use App\Modules\Plataforma\Http\Controllers\UsuarioPlataformaController;
use Illuminate\Support\Facades\Route;

// Rutas del panel de plataforma. Se cargan dentro del grupo auth:api + plataforma
// con prefijo /plataforma (routes/api.php), sin X-Condominio-Id: el permiso se mira
// en el equipo 0 (roles de plataforma) y cada ruta exige el suyo.

$condominios = 'permission:'.Permiso::PlataformaCondominios->value;

Route::get('planes', [CatalogoController::class, 'planes'])->middleware($condominios);
Route::get('amenidades', [CatalogoController::class, 'amenidades'])->middleware($condominios);
Route::get('usuarios/buscar', [UsuarioPlataformaController::class, 'buscar'])->middleware($condominios);

Route::get('condominios', [CondominioController::class, 'index'])->middleware($condominios);
Route::post('condominios', [CondominioController::class, 'store'])->middleware($condominios);
Route::get('condominios/{condominio}', [CondominioController::class, 'show'])->whereNumber('condominio')->middleware($condominios);
