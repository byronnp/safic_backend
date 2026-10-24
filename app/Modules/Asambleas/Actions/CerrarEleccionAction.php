<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Access\Catalogo;
use App\Core\Access\RolInfo;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Realtime\EnVivo;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\Candidato;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Unidades\Actions\ResumenPersonasAction;
use App\Modules\Usuarios\Actions\AsignarCargoAction;
use App\Modules\Usuarios\Models\CargoDirectiva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Caso de uso del presidente: cerrar el punto de elección de directiva. Los ganadores de cada cargo se nombran solos (`AsignarCargoAction`:
 * se cierra el periodo de quien salía y se abre el nuevo) con la asamblea como respaldo. Cada cargo es único y una persona no puede
 * ocupar dos: si alguien ganó varios cargos, la mesa elige cuál acepta antes de cerrar; los demás quedan vacantes y se vuelven a convocar.
 * Un cargo sin ganador (nadie alcanzó la mayoría, o empate) también queda vacante. Si un ganador ya no cumple (cayó en mora, perdió su
 * correo), su cargo queda vacante con el motivo: los demás nombramientos siguen.
 */
final class CerrarEleccionAction
{
    public function __construct(
        private readonly AsignarCargoAction $asignar,
        private readonly ResumenPersonasAction $personas,
        private readonly Calendario $calendario,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<int|string, string>  $acepta  persona_id => cargo que acepta, para quien ganó más de uno
     * @return array{cargos: list<array<string, mixed>>}
     */
    public function execute(User $user, int $puntoId, string $periodoHasta, array $acepta): array
    {
        $r = DB::transaction(function () use ($user, $puntoId, $periodoHasta, $acepta): array {
            $punto = AsambleaPunto::query()->lockForUpdate()->findOrFail($puntoId);
            if ($punto->tipo !== AsambleaPunto::ELECCION) {
                throw new ApiException('PUNTO_NO_ES_ELECCION', 'Este punto no es una elección de directiva.', 422);
            }
            if ($punto->eleccion_cerrada_en !== null) {
                throw new ApiException('ELECCION_CERRADA', 'La elección ya se cerró.', 409);
            }
            $asamblea = Asamblea::query()->lockForUpdate()->findOrFail($punto->asamblea_id);
            if ($asamblea->estado === Asamblea::EN_VOTACION) {
                throw new ApiException('VOTACION_ABIERTA', 'Hay una votación abierta: ciérrala antes de cerrar la elección.', 409);
            }
            if ($asamblea->estado !== Asamblea::INSTALADA) {
                throw new ApiException('ASAMBLEA_NO_INSTALADA', 'La elección se cierra en una asamblea instalada.', 409);
            }

            $candidatos = Candidato::query()->where('punto_id', $punto->id)->get();
            $cargosConCandidatos = $candidatos->pluck('cargo')->unique()->values();
            if ($cargosConCandidatos->isEmpty()) {
                throw new ApiException('SIN_CANDIDATOS', 'El punto no tiene candidatos: postula al menos uno por cargo.', 422);
            }
            $votaciones = Votacion::query()->where('punto_id', $punto->id)->whereNotNull('cargo')->get()->keyBy('cargo');
            $faltan = $cargosConCandidatos->filter(fn (string $c): bool => ! $votaciones->has($c) || $votaciones[$c]->estaAbierta())->values();
            if ($faltan->isNotEmpty()) {
                throw new ApiException('ELECCION_INCOMPLETA', 'Falta votar y cerrar: '.$faltan->map(fn (string $c): string => app(Catalogo::class)->etiquetaDeRol($c))->implode(', ').'.', 409, ['cargos' => $faltan->all()]);
            }

            // Ganador de cada cargo (persona)
            $ganadores = [];
            foreach (CargoDirectiva::cargos() as $rol) {
                $v = $votaciones->get($rol->clave);
                if ($v === null || $v->ganador_candidato_id === null) {
                    continue;
                }
                $ganadores[$rol->clave] = $candidatos->firstWhere('id', $v->ganador_candidato_id)?->persona_id;
            }
            $ganadores = array_filter($ganadores);

            // Una persona que ganó varios cargos elige uno antes de cerrar
            $conflictos = [];
            foreach (array_count_values($ganadores) as $personaId => $n) {
                if ($n > 1) {
                    $suyos = array_keys(array_filter($ganadores, fn ($p): bool => $p === $personaId));
                    $elegido = $acepta[$personaId] ?? null;
                    if ($elegido === null || ! in_array($elegido, $suyos, true)) {
                        $conflictos[] = ['persona_id' => $personaId, 'cargos' => $suyos];
                    } else {
                        foreach ($suyos as $c) {
                            if ($c !== $elegido) {
                                unset($ganadores[$c]);
                            }
                        }
                    }
                }
            }
            if ($conflictos !== []) {
                $nombres = $this->personas->execute(array_column($conflictos, 'persona_id'));
                $detalle = array_map(fn (array $c): array => $c + ['nombre' => $nombres[$c['persona_id']]['nombre'] ?? 'Persona'], $conflictos);

                throw new ApiException('CONFLICTO_DE_CARGOS', 'Una persona ganó más de un cargo: la mesa elige cuál acepta antes de cerrar la elección.', 422, ['conflictos' => $detalle]);
            }

            return $this->nombrar($user, $punto, $asamblea, $votaciones, $candidatos, $ganadores, $periodoHasta);
        });
        EnVivo::aAsambleas($this->tenant->require(), 'asambleas', $puntoId);

        return $r;
    }

    /**
     * @param  Collection<string, Votacion>  $votaciones
     * @param  Collection<int, Candidato>  $candidatos
     * @param  array<string, int>  $ganadores
     * @return array{cargos: list<array<string, mixed>>}
     */
    private function nombrar(User $user, AsambleaPunto $punto, Asamblea $asamblea, $votaciones, $candidatos, array $ganadores, string $periodoHasta): array
    {
        $nombres = $this->personas->execute(array_values($ganadores));
        $fecha = CarbonImmutable::instance($asamblea->fecha_hora)->setTimezone($this->calendario->zonaHoraria())->format('d/m/Y');
        $acta = Str::limit("Asamblea {$fecha}: {$asamblea->titulo}", 80, '');

        // Quien ya ocupa otro cargo queda libre cuando se nombra a su reemplazo: se reintenta lo que choque con un cargo aún ocupado
        $pendientes = $ganadores;
        $resultado = [];
        for ($ronda = 0; $ronda < count($ganadores) + 1 && $pendientes !== []; $ronda++) {
            foreach ($pendientes as $cargo => $personaId) {
                try {
                    $this->asignar->execute(app(Catalogo::class)->rol($cargo) ?? throw new ApiException('ROL_NO_VALIDO', 'Ese cargo no existe.', 422), $personaId, $acta, $periodoHasta, $user);
                    $resultado[$cargo] = ['estado' => 'nombrado', 'motivo' => null];
                    unset($pendientes[$cargo]);
                } catch (ApiException $e) {
                    if ($e->errorCode === 'PERSONA_CON_CARGO' && $ronda < count($ganadores)) {
                        continue;   // se vuelve a intentar cuando se libere su cargo anterior
                    }
                    $resultado[$cargo] = ['estado' => 'no_nombrado', 'motivo' => $e->getMessage()];
                    unset($pendientes[$cargo]);
                }
            }
        }

        $cargos = array_map(function (RolInfo $rol) use ($votaciones, $candidatos, $ganadores, $resultado, $nombres): array {
            $v = $votaciones->get($rol->clave);
            $personaId = $ganadores[$rol->clave] ?? null;
            $ganoOtro = $v?->ganador_candidato_id !== null && $personaId === null;
            $base = ['cargo' => $rol->clave, 'etiqueta' => $rol->etiqueta, 'persona_id' => $personaId, 'nombre' => $personaId === null ? null : ($nombres[$personaId]['nombre'] ?? null)];

            if ($v === null) {
                return $base + ['estado' => 'sin_votacion', 'motivo' => null];
            }
            if ($v->ganador_candidato_id === null) {
                return $base + ['estado' => 'vacante', 'motivo' => 'Nadie alcanzó la mayoría (o hubo empate): el cargo queda vacante y se vuelve a convocar.'];
            }
            if ($ganoOtro) {
                $quien = $candidatos->firstWhere('id', $v->ganador_candidato_id)?->persona_id;

                return $base + ['persona_id' => $quien, 'nombre' => $quien === null ? null : ($this->personas->execute([$quien])[$quien]['nombre'] ?? null),
                    'estado' => 'vacante', 'motivo' => 'Quien ganó este cargo aceptó otro: queda vacante y se vuelve a convocar.'];
            }

            return $base + ($resultado[$rol->clave] ?? ['estado' => 'vacante', 'motivo' => null]);
        }, CargoDirectiva::cargos());
        $cargos = array_values(array_filter($cargos, fn (array $c): bool => $c['estado'] !== 'sin_votacion'));

        $punto->forceFill(['eleccion_cerrada_en' => now(), 'nombramientos' => $cargos])->save();

        return ['cargos' => $cargos];
    }
}
