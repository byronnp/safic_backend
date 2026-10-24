<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Access\RolInfo;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\Candidato;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Finanzas\Actions\PersonaEnMoraAction;
use App\Modules\Unidades\Actions\PropietariosVigentesAction;
use App\Modules\Unidades\Actions\ResumenPersonasAction;
use App\Modules\Usuarios\Models\CargoDirectiva;

/**
 * Los cargos de un punto de elección con sus candidatos y la votación de cada uno, y quiénes pueden postularse (propietarios; no
 * lo está quien debe cuotas ni quien no tiene correo, con el motivo).
 */
final class ListarCandidatosEleccionAction
{
    public function __construct(
        private readonly ResumenPersonasAction $personas,
        private readonly PropietariosVigentesAction $propietarios,
        private readonly PersonaEnMoraAction $enMora,
    ) {}

    /**
     * Los cargos con candidatos y su estado (sin la lista de quiénes pueden postularse).
     *
     * @return array{cerrada: bool, nombramientos: list<array<string, mixed>>|null, cargos: list<array<string, mixed>>}
     */
    public function cargos(AsambleaPunto $punto): array
    {
        $candidatos = Candidato::query()->where('punto_id', $punto->id)->orderBy('id')->get();
        $nombres = $this->personas->execute($candidatos->pluck('persona_id')->unique()->all());
        $votaciones = Votacion::query()->where('punto_id', $punto->id)->whereNotNull('cargo')->get()->keyBy('cargo');

        $cargos = array_map(function (RolInfo $rol) use ($candidatos, $nombres, $votaciones, $punto): array {
            $v = $votaciones->get($rol->clave);

            return [
                'cargo' => $rol->clave, 'etiqueta' => $rol->etiqueta,
                'candidatos' => $candidatos->where('cargo', $rol->clave)->map(fn (Candidato $c): array => [
                    'id' => $c->id, 'persona_id' => $c->persona_id, 'nombre' => $nombres[$c->persona_id]['nombre'] ?? 'Persona dada de baja', 'unidad' => $nombres[$c->persona_id]['unidad'] ?? null,
                ])->values()->all(),
                'votacion' => $v === null ? null : ['id' => $v->id, 'estado' => $v->estado, 'resultado' => $v->resultado, 'ganador_candidato_id' => $v->ganador_candidato_id],
                'puede_abrir' => $punto->eleccion_cerrada_en === null && $v === null && $candidatos->where('cargo', $rol->clave)->isNotEmpty(),
            ];
        }, CargoDirectiva::cargos());

        return ['cerrada' => $punto->eleccion_cerrada_en !== null, 'nombramientos' => $punto->nombramientos, 'cargos' => $cargos];
    }

    /** @return array{cerrada: bool, nombramientos: list<array<string, mixed>>|null, cargos: list<array<string, mixed>>, elegibles: list<array<string, mixed>>} */
    public function execute(int $puntoId): array
    {
        $punto = AsambleaPunto::query()->findOrFail($puntoId);

        $elegibles = array_map(function (array $p): array {
            $motivo = match (true) {
                $p['email'] === null || $p['email'] === '' => 'sin_correo',
                $this->enMora->execute($p['persona_id']) => 'en_mora',
                default => null,
            };

            return ['persona_id' => $p['persona_id'], 'nombre' => $p['nombre'], 'unidad' => $p['unidad'], 'disponible' => $motivo === null, 'motivo' => $motivo];
        }, $this->propietarios->execute());

        return $this->cargos($punto) + ['elegibles' => $elegibles];
    }
}
