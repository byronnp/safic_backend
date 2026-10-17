<?php

namespace App\Modules\Unidades\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Unidades\Models\Bloque;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\DB;

/**
 * Consulta pública del módulo Unidades para Comunicación: a qué unidades llega un anuncio (todo el condominio, un bloque o unas
 * unidades por su código) y qué personas viven en ellas. Solo cuentan las unidades con alguien viviendo: las vacías no leen nada.
 */
final class DestinatariosDeAnuncioAction
{
    /**
     * @param  list<string>|null  $codigos
     * @return array{unidades: list<array{id: int, codigo: string}>, personas: list<array{unidad_id: int, nombre: string, email: string|null, tiene_cuenta: bool}>, texto: string}
     */
    public function execute(string $tipo, ?int $bloqueId, ?array $codigos): array
    {
        $consulta = Unidad::query()->whereHas('ocupantesVigentes', fn ($q) => $q->whereIn('relacion', ['propietario', 'inquilino', 'residente']));
        $texto = 'Todo el condominio';

        if ($tipo === 'bloque') {
            $bloque = Bloque::query()->find($bloqueId) ?? throw new ApiException('BLOQUE_NO_ENCONTRADO', 'Ese bloque no existe.', 422);
            $consulta->where('bloque_id', $bloque->id);
            $texto = $bloque->nombre;
        } elseif ($tipo === 'unidades') {
            $pedidos = array_values(array_unique(array_map(fn (string $c): string => mb_strtolower(trim($c)), $codigos ?? [])));
            if ($pedidos === []) {
                throw new ApiException('SIN_UNIDADES', 'Indica al menos una unidad.', 422);
            }
            $existentes = Unidad::query()->whereIn(DB::raw('lower(codigo)'), $pedidos)->get(['id', 'codigo']);
            $faltan = array_values(array_diff($pedidos, $existentes->map(fn (Unidad $u): string => mb_strtolower($u->codigo))->all()));
            if ($faltan !== []) {
                throw new ApiException('UNIDADES_NO_ENCONTRADAS', 'No existen estas unidades: '.implode(', ', array_map('mb_strtoupper', array_slice($faltan, 0, 8))).'.', 422);
            }
            $consulta->whereIn('id', $existentes->pluck('id'));
            $texto = count($pedidos) === 1 ? '1 unidad' : count($pedidos).' unidades';
        }

        $unidades = $consulta->orderBy('codigo')->get(['id', 'codigo']);
        $personas = [];
        $vistas = [];
        foreach (Ocupante::query()->vigentes()->whereIn('unidad_id', $unidades->pluck('id'))->whereIn('relacion', ['propietario', 'inquilino', 'residente'])->with('persona')->get() as $o) {
            if ($o->persona->trashed() || isset($vistas[$o->unidad_id.'-'.$o->persona_id])) {
                continue;
            }
            $vistas[$o->unidad_id.'-'.$o->persona_id] = true;
            $personas[] = ['unidad_id' => $o->unidad_id, 'nombre' => $o->persona->nombreCompleto(), 'email' => $o->persona->email, 'tiene_cuenta' => $o->persona->user_id !== null];
        }

        return [
            'unidades' => $unidades->map(fn (Unidad $u): array => ['id' => $u->id, 'codigo' => $u->codigo])->values()->all(),
            'personas' => $personas,
            'texto' => $texto,
        ];
    }
}
