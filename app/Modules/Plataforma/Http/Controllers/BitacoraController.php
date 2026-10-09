<?php

namespace App\Modules\Plataforma\Http\Controllers;

use App\Core\Audit\RegistroBitacora;
use App\Core\Http\Responses\ApiResponse;
use App\Modules\Plataforma\Http\Resources\RegistroBitacoraResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bitácora de cambios de plataforma (condominios, membresías, catálogo de amenidades, menú,
 * roles y permisos). Solo lectura: nadie puede editar ni borrar un registro.
 */
class BitacoraController
{
    /** Entidades que registra la bitácora, para el filtro de la pantalla. */
    public const ENTIDADES = ['condominio', 'membresia', 'catalogo_amenidad', 'menu', 'rol'];

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'entidad' => ['sometimes', Rule::in(self::ENTIDADES)],
            'evento' => ['sometimes', Rule::in(['creado', 'actualizado', 'eliminado'])],
            'usuario_id' => ['sometimes', 'integer'],
            'condominio_id' => ['sometimes', 'integer'],
            'desde' => ['sometimes', 'date'],
            'hasta' => ['sometimes', 'date', 'after_or_equal:desde'],
            'buscar' => ['sometimes', 'string', 'max:80'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ], [
            'entidad.in' => 'Elige un tipo de registro de la lista.',
            'evento.in' => 'Elige creado, actualizado o eliminado.',
            'desde.date' => 'La fecha inicial no es válida.',
            'hasta.date' => 'La fecha final no es válida.',
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        $patron = isset($f['buscar']) ? '%'.addcslashes(trim($f['buscar']), '%_\\').'%' : null;

        $pagina = RegistroBitacora::query()
            ->when(isset($f['entidad']), fn ($q) => $q->where('entidad', $f['entidad']))
            ->when(isset($f['evento']), fn ($q) => $q->where('evento', $f['evento']))
            ->when(isset($f['usuario_id']), fn ($q) => $q->where('user_id', $f['usuario_id']))
            ->when(isset($f['condominio_id']), fn ($q) => $q->where('condominio_id', $f['condominio_id']))
            ->when(isset($f['desde']), fn ($q) => $q->where('created_at', '>=', $f['desde'].' 00:00:00'))
            ->when(isset($f['hasta']), fn ($q) => $q->where('created_at', '<=', $f['hasta'].' 23:59:59'))
            ->when($patron !== null, fn ($q) => $q->where(fn ($w) => $w
                ->where('etiqueta', 'ilike', $patron)
                ->orWhere('user_nombre', 'ilike', $patron)))
            ->orderByDesc('id')
            ->paginate((int) ($f['por_pagina'] ?? 25));

        return ApiResponse::paginated($pagina, RegistroBitacoraResource::class);
    }
}
