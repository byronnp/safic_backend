<?php

namespace App\Modules\Unidades\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: asignar una persona a una unidad (propietario, inquilino, residente
 * o contacto de emergencia) con sus fechas. Reglas:
 * - una unidad tiene como máximo un ocupante principal en cada periodo (409 PRINCIPAL_OCUPADO);
 * - la misma persona no repite la misma relación en periodos que se cruzan (409 OCUPANTE_DUPLICADO).
 */
final class AsignarOcupanteAction
{
    /**
     * @param  array{persona_id: int, relacion: string, es_principal?: bool, fecha_inicio: string, fecha_fin?: string|null}  $datos
     */
    public function execute(Unidad $unidad, array $datos): Ocupante
    {
        return DB::transaction(function () use ($unidad, $datos): Ocupante {
            // Bloquea la unidad: dos asignaciones simultáneas no pasan la regla del principal
            Unidad::query()->whereKey($unidad->id)->lockForUpdate()->firstOrFail();

            $inicio = $datos['fecha_inicio'];
            $fin = $datos['fecha_fin'] ?? null;
            $esPrincipal = (bool) ($datos['es_principal'] ?? false);

            $seCruzan = fn () => Ocupante::query()
                ->where('unidad_id', $unidad->id)
                ->when($fin !== null, fn (Builder $q) => $q->where('fecha_inicio', '<=', $fin))
                ->where(fn (Builder $q) => $q->whereNull('fecha_fin')->orWhere('fecha_fin', '>=', $inicio));

            if ($esPrincipal) {
                $principal = $seCruzan()->where('es_principal', true)->with('persona')->first();
                if ($principal !== null) {
                    throw new ApiException(
                        'PRINCIPAL_OCUPADO',
                        "La unidad ya tiene como ocupante principal a {$principal->persona->nombreCompleto()}; finaliza esa ocupación o asígnalo sin marcar principal.",
                        409,
                        ['ocupante_id' => $principal->id],
                    );
                }
            }

            $duplicado = $seCruzan()
                ->where('persona_id', $datos['persona_id'])
                ->where('relacion', $datos['relacion'])
                ->exists();

            if ($duplicado) {
                throw new ApiException('OCUPANTE_DUPLICADO', 'Esta persona ya tiene esa relación con la unidad en esas fechas.', 409);
            }

            return Ocupante::create([
                'unidad_id' => $unidad->id,
                'persona_id' => $datos['persona_id'],
                'relacion' => $datos['relacion'],
                'es_principal' => $esPrincipal,
                'fecha_inicio' => $inicio,
                'fecha_fin' => $fin,
            ])->load('persona');
        });
    }
}
