<?php

use App\Core\Permissions\Permiso;
use App\Modules\Plataforma\Http\Controllers\BitacoraController;
use App\Modules\Plataforma\Http\Controllers\CatalogoAmenidadesController;
use App\Modules\Plataforma\Http\Controllers\CatalogoController;
use App\Modules\Plataforma\Http\Controllers\CondominioController;
use App\Modules\Plataforma\Http\Controllers\MenuSistemaController;
use App\Modules\Plataforma\Http\Controllers\RolesAdminController;
use App\Modules\Plataforma\Http\Controllers\UsuarioPlataformaController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Plataforma en el panel del super admin. routes/api.php las carga
// dentro del grupo auth:api + plataforma con prefijo /plataforma (sin X-Condominio-Id):
// el permiso se mira en el equipo 0 (roles de plataforma). Cada ruta exige su permiso.

$condominios = 'permission:'.Permiso::PlataformaCondominios->value;
$roles = 'permission:'.Permiso::PlataformaRoles->value;
$auditoria = 'permission:'.Permiso::PlataformaAuditoria->value;

Route::get('planes', [CatalogoController::class, 'planes'])->middleware($condominios);
Route::get('amenidades', [CatalogoController::class, 'amenidades'])->middleware($condominios);
Route::get('usuarios/buscar', [UsuarioPlataformaController::class, 'buscar'])->middleware($condominios);
Route::post('usuarios/{usuario}/doble-factor/restablecer', [UsuarioPlataformaController::class, 'restablecerDobleFactor'])->whereNumber('usuario')->middleware($condominios);

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

// Menú del sistema (catálogo global de pantallas del menú): solo quien gestiona roles y permisos
Route::get('menu-sistema', [MenuSistemaController::class, 'index'])->middleware($roles);
Route::get('menu-sistema/vista-previa', [MenuSistemaController::class, 'vistaPrevia'])->middleware($roles);
Route::post('menu-sistema', [MenuSistemaController::class, 'store'])->middleware($roles);
Route::patch('menu-sistema/{item}', [MenuSistemaController::class, 'update'])->whereNumber('item')->middleware($roles);
Route::post('menu-sistema/{item}/mover', [MenuSistemaController::class, 'mover'])->whereNumber('item')->middleware($roles);

// Roles y permisos (plantillas globales de los condominios)
Route::get('roles', [RolesAdminController::class, 'index'])->middleware($roles);
Route::post('roles', [RolesAdminController::class, 'store'])->middleware($roles);
Route::put('roles/{rol}/permisos', [RolesAdminController::class, 'permisos'])->where('rol', '[a-z0-9_]+')->middleware($roles);

// Bitácora de cambios de plataforma (solo lectura)
Route::get('bitacora', [BitacoraController::class, 'index'])->middleware($auditoria);
