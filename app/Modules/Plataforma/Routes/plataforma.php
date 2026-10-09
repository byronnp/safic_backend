<?php

use App\Core\Permissions\Permiso;
use App\Modules\Plataforma\Http\Controllers\CatalogoAmenidadesController;
use App\Modules\Plataforma\Http\Controllers\CatalogoController;
use App\Modules\Plataforma\Http\Controllers\CondominioController;
use App\Modules\Plataforma\Http\Controllers\UsuarioPlataformaController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Plataforma en el panel del super admin. routes/api.php las carga
// dentro del grupo auth:api + plataforma con prefijo /plataforma (sin X-Condominio-Id):
// el permiso se mira en el equipo 0 (roles de plataforma). Cada ruta exige su permiso.

$condominios = 'permission:'.Permiso::PlataformaCondominios->value;

Route::get('planes', [CatalogoController::class, 'planes'])->middleware($condominios);
Route::get('amenidades', [CatalogoController::class, 'amenidades'])->middleware($condominios);
Route::get('usuarios/buscar', [UsuarioPlataformaController::class, 'buscar'])->middleware($condominios);

Route::get('condominios', [CondominioController::class, 'index'])->middleware($condominios);
Route::post('condominios', [CondominioController::class, 'store'])->middleware($condominios);
Route::get('condominios/{condominio}', [CondominioController::class, 'show'])->whereNumber('condominio')->middleware($condominios);
Route::post('condominios/{condominio}/administradores/{usuario}/invitacion', [CondominioController::class, 'reenviarInvitacion'])
    ->whereNumber(['condominio', 'usuario'])
    ->middleware($condominios);

// Catálogo global de amenidades y amenidades propias de los condominios
Route::get('catalogo-amenidades', [CatalogoAmenidadesController::class, 'index'])->middleware($condominios);
Route::get('catalogo-amenidades/propias', [CatalogoAmenidadesController::class, 'propias'])->middleware($condominios);
Route::post('catalogo-amenidades', [CatalogoAmenidadesController::class, 'store'])->middleware($condominios);
Route::patch('catalogo-amenidades/{amenidad}', [CatalogoAmenidadesController::class, 'update'])->whereNumber('amenidad')->middleware($condominios);
Route::delete('catalogo-amenidades/{amenidad}', [CatalogoAmenidadesController::class, 'destroy'])->whereNumber('amenidad')->middleware($condominios);
Route::post('catalogo-amenidades/propias/{condominio}/{amenidad}/promover', [CatalogoAmenidadesController::class, 'promover'])
    ->whereNumber(['condominio', 'amenidad'])
    ->middleware($condominios);
