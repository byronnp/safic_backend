<?php

namespace App\Modules\Usuarios\Actions;

use App\Core\Tenancy\Calendario;
use App\Modules\Unidades\Actions\ResumenPersonasAction;
use App\Modules\Usuarios\Models\CargoDirectiva;

/**
 * Consulta pública del módulo Usuarios: los cuatro cargos de la directiva con quién los
 * ocupa hoy. Un cargo cuyo periodo venció sigue "prorrogado" hasta nombrar reemplazo.
 */
final class ListarDirectivaAction
{
    public function __construct(
        private readonly ResumenPersonasAction $personas,
        private readonly Calendario $calendario,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        $abiertos = CargoDirectiva::query()->whereNull('cerrado_en')->get()->keyBy('cargo');
        $resumen = $this->personas->execute($abiertos->pluck('persona_id')->all());
        $hoy = $this->calendario->hoy();

        return array_map(function ($rol) use ($abiertos, $resumen, $hoy): array {
            /** @var CargoDirectiva|null $fila */
            $fila = $abiertos->get($rol->value);
            $persona = $fila === null ? null : ($resumen[$fila->persona_id] ?? null);

            return [
                'cargo' => $rol->value,
                'etiqueta' => $rol->etiqueta(),
                'estado' => match (true) {
                    $fila === null => 'vacante',
                    $fila->periodo_fin->toDateString() < $hoy => 'prorrogado',
                    default => 'vigente',
                },
                'titular' => $fila === null ? null : [
                    'persona_id' => $fila->persona_id,
                    'nombre' => $persona['nombre'] ?? 'Persona dada de baja',
                    'unidad' => $persona['unidad'] ?? null,
                    // Si vendió su unidad ya no cumple el requisito: se avisa para nombrar reemplazo
                    'sigue_siendo_propietario' => $persona['propietario'] ?? false,
                ],
                'periodo_inicio' => $fila?->periodo_inicio->toDateString(),
                'periodo_fin' => $fila?->periodo_fin->toDateString(),
                'acta' => $fila?->acta,
            ];
        }, CargoDirectiva::cargos());
    }
}
