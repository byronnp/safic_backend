<?php

use App\Modules\Plataforma\Http\Controllers\CatalogoController;
use Illuminate\Support\Facades\Route;

// Catálogos compartidos del módulo Plataforma. routes/api.php las carga dentro del
// grupo auth:api (cualquier usuario con sesión, sin condominio): no exponen datos
// de ningún condominio, por eso no piden permiso.

Route::get('ubicaciones', [CatalogoController::class, 'ubicaciones']);
