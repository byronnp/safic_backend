<?php

namespace App\Modules\Usuarios\Actions;

use App\Core\Permissions\Rol;
use App\Modules\Unidades\Actions\PropietariosVigentesAction;
use App\Modules\Usuarios\Models\CargoDirectiva;

/**
 * Consulta pública del módulo Usuarios: quién puede ocupar un cargo. Solo propietarios;
 * quien ya ocupa otro cargo o no tiene correo (necesita cuenta para entrar) aparece
 * como no disponible, con el motivo.
 */
final class ListarCandidatosDirectivaAction
{
    public function __construct(private readonly PropietariosVigentesAction $propietarios) {}

    /**
     * @return list<array{persona_id: int, nombre: string, unidad: string, disponible: bool, motivo: string|null, cargo_actual: string|null}>
     */
    public function execute(Rol $cargo): array
    {
        $cargos = CargoDirectiva::query()->whereNull('cerrado_en')->pluck('cargo', 'persona_id')->all();

        $candidatos = [];
        foreach ($this->propietarios->execute() as $p) {
            $actual = $cargos[$p['persona_id']] ?? null;

            // Quien ya ocupa este cargo no es candidato para "cambiarlo" por sí mismo
            if ($actual === $cargo->value) {
                continue;
            }

            $motivo = match (true) {
                $actual !== null => 'ocupa_cargo',
                $p['email'] === null || $p['email'] === '' => 'sin_correo',
                default => null,
            };

            $candidatos[] = [
                'persona_id' => $p['persona_id'],
                'nombre' => $p['nombre'],
                'unidad' => $p['unidad'],
                'disponible' => $motivo === null,
                'motivo' => $motivo,
                'cargo_actual' => $actual,
            ];
        }

        return $candidatos;
    }
}
