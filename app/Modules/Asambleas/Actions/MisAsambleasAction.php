<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Permissions\Rol;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Asambleas\Models\Acta;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaAsistencia;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\AsambleaUnidad;
use App\Modules\Asambleas\Models\Candidato;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Asambleas\Models\Voto;
use App\Modules\Asambleas\Services\DerechoAVoto;
use App\Modules\Unidades\Actions\ResumenPersonasAction;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta para el propietario: las asambleas convocadas o en curso, con sus unidades (solo vota el propietario, Art. 41), el poder
 * que dio por cada una y las unidades que representa por poder de otros.
 */
final class MisAsambleasAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly UnidadesDeCuentaAction $unidades,
        private readonly DerechoAVoto $derecho,
        private readonly ResumenPersonasAction $personas,
    ) {}

    /** @return list<array<string, mixed>> */
    public function execute(User $user): array
    {
        $propias = collect($this->unidades->execute($user->id))->where('relacion', 'propietario')->values();
        $personas = Persona::query()->where('user_id', $user->id)->pluck('id')->all();
        $asambleas = Asamblea::query()->whereIn('estado', [Asamblea::CONVOCADA, Asamblea::EN_REGISTRO, Asamblea::INSTALADA, Asamblea::EN_VOTACION, Asamblea::CERRADA, Asamblea::ACTA_FIRMADA])->orderBy('fecha_hora')->get();
        $actas = Acta::query()->whereIn('asamblea_id', $asambleas->pluck('id'))->get()->keyBy('asamblea_id');
        $poderes = AsambleaPoder::query()->whereIn('asamblea_id', $asambleas->pluck('id'))->where('estado', '!=', AsambleaPoder::REVOCADO)->get()->groupBy('asamblea_id');
        $alicuotas = Unidad::query()->whereIn('id', $propias->pluck('unidad_id'))->pluck('alicuota', 'id');
        $presentes = AsambleaAsistencia::query()->presentes()->whereIn('asamblea_id', $asambleas->pluck('id'))->whereIn('unidad_id', $propias->pluck('unidad_id')->merge($poderes->flatten()->pluck('unidad_id'))->unique())->get()->groupBy('asamblea_id');
        $codigos = Unidad::query()->withTrashed()->whereIn('id', $poderes->flatten()->pluck('unidad_id'))->pluck('codigo', 'id');

        return $asambleas->map(function (Asamblea $a) use ($user, $propias, $poderes, $alicuotas, $codigos, $personas, $presentes, $actas): array {
            $deLaAsamblea = $poderes->get($a->id, collect());
            $mias = $propias->map(function (array $u) use ($deLaAsamblea, $alicuotas): array {
                /** @var AsambleaPoder|null $p */
                $p = $deLaAsamblea->firstWhere('unidad_id', $u['unidad_id']);

                return [
                    'unidad_id' => $u['unidad_id'], 'codigo' => $u['codigo'], 'alicuota' => (string) ($alicuotas[$u['unidad_id']] ?? '0'),
                    'poder' => $p === null ? null : ['id' => $p->id, 'estado' => $p->estado, 'tipo' => $p->tipo, 'representante_nombre' => $p->representante_nombre, 'motivo_rechazo' => $p->motivo_rechazo],
                ];
            })->all();
            $representa = $deLaAsamblea->filter(fn (AsambleaPoder $p): bool => $p->estado === AsambleaPoder::VALIDADO && in_array($p->representante_persona_id, $personas, true))
                ->map(fn (AsambleaPoder $p): array => ['poder_id' => $p->id, 'unidad_codigo' => (string) ($codigos[$p->unidad_id] ?? '—')])->values()->all();

            return [
                'id' => $a->id, 'titulo' => $a->titulo, 'tipo' => $a->tipo, 'modalidad' => $a->modalidad, 'estado' => $a->estado,
                'fecha_hora' => $a->fecha_hora->setTimezone($this->calendario->zonaHoraria())->format('Y-m-d\TH:i'),
                'lugar' => $a->lugar, 'enlace_video' => $a->enlace_video,
                'unidades' => $mias, 'representa' => $representa,
                'puede_dar_poder' => $a->estaConvocada() && $mias !== [],
                // «Estoy presente» desde la app: solo asambleas virtuales o mixtas con el registro abierto y alguna unidad con derecho
                'registro_abierto' => $a->registroAbierto(),
                'puede_marcar_presencia' => $a->registroAbierto() && in_array($a->modalidad, ['virtual', 'mixta'], true) && ($mias !== [] || $representa !== []),
                'presente' => ($presentes->get($a->id) ?? collect())->contains(fn (AsambleaAsistencia $p): bool => $p->canal === 'app'),
                // Votación abierta (sin resultados: solo la mesa los ve en vivo) y resultados de las ya cerradas
                'votacion' => $this->votacionAbierta($user, $a),
                'resultados' => $this->resultados($a),
                // El acta aparece cuando ya la firmaron el presidente y el secretario
                'acta' => ($acta = $actas->get($a->id)) !== null && $acta->estaFirmada() ? ['asamblea_id' => $a->id, 'numero' => $acta->numero, 'publicada_en' => $acta->publicada_en?->setTimezone($this->calendario->zonaHoraria())->toIso8601String()] : null,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function votacionAbierta(User $user, Asamblea $a): ?array
    {
        $v = Votacion::query()->where('asamblea_id', $a->id)->where('estado', Votacion::ABIERTA)->first();
        if ($v === null) {
            return null;
        }
        $punto = AsambleaPunto::query()->findOrFail($v->punto_id);
        $foto = AsambleaUnidad::query()->where('asamblea_id', $a->id)->get()->keyBy('unidad_id');
        $votos = Voto::query()->where('votacion_id', $v->id)->get()->keyBy('unidad_id');

        $mias = array_map(function (int $unidadId) use ($a, $foto, $votos): array {
            $voto = $votos->get($unidadId);
            $impedimento = $voto === null ? $this->derecho->impedimento($a, $unidadId) : null;

            return [
                'unidad_id' => $unidadId, 'codigo' => (string) ($foto[$unidadId]->codigo ?? '—'),
                'alicuota' => (string) ($foto[$unidadId]->alicuota ?? '0'),
                'puede_votar' => $voto === null && $impedimento === null,
                // Cada quien ve su propio voto, también si la votación es secreta
                'voto' => $voto?->opcion, 'voto_candidato_id' => $voto?->candidato_id, 'motivo' => $impedimento['mensaje'] ?? null,
            ];
        }, $this->derecho->unidadesDeLaCuenta($user, $a));

        return [
            'id' => $v->id, 'punto_id' => $punto->id, 'orden' => $punto->orden, 'titulo' => $punto->titulo, 'descripcion' => $punto->descripcion,
            'mayoria' => $v->mayoria, 'umbral_pct' => $v->umbral_pct === null ? null : (string) $v->umbral_pct, 'secreta' => $v->secreta, 'unidades' => $mias,
            // Elección de directiva: la papeleta del cargo (sin conteo)
            'cargo' => $v->cargo, 'cargo_etiqueta' => $v->esEleccion() ? Rol::from($v->cargo)->etiqueta() : null,
            'candidatos' => $v->esEleccion() ? $this->candidatos($v) : [],
        ];
    }

    /** @return list<array<string, mixed>> Resultados de las votaciones cerradas: los asistentes los ven al cerrarse. */
    private function resultados(Asamblea $a): array
    {
        return Votacion::query()->where('asamblea_id', $a->id)->where('estado', Votacion::CERRADA)->orderBy('cerrada_en')->get()->map(function (Votacion $v): array {
            $punto = AsambleaPunto::query()->findOrFail($v->punto_id);
            $ganador = $v->ganador_candidato_id === null ? null : Candidato::query()->find($v->ganador_candidato_id);

            return [
                'punto_id' => $punto->id, 'orden' => $punto->orden, 'titulo' => $punto->titulo, 'resultado' => $v->resultado,
                'cargo_etiqueta' => $v->esEleccion() ? Rol::from($v->cargo)->etiqueta() : null,
                'ganador' => $ganador === null ? null : ($this->personas->execute([$ganador->persona_id])[$ganador->persona_id]['nombre'] ?? null),
                'alicuota_si' => $v->esEleccion() ? null : (string) $v->alicuota_si, 'alicuota_no' => $v->esEleccion() ? null : (string) $v->alicuota_no,
                'alicuota_abstencion' => $v->esEleccion() ? null : (string) $v->alicuota_abstencion,
                'base_alicuota' => (string) $v->base_alicuota,
            ];
        })->all();
    }

    /** @return list<array{id: int, nombre: string, unidad: string|null}> */
    private function candidatos(Votacion $v): array
    {
        $candidatos = Candidato::query()->where('punto_id', $v->punto_id)->where('cargo', $v->cargo)->orderBy('id')->get();
        $nombres = $this->personas->execute($candidatos->pluck('persona_id')->unique()->all());

        return $candidatos->map(fn (Candidato $c): array => [
            'id' => $c->id, 'nombre' => $nombres[$c->persona_id]['nombre'] ?? 'Persona dada de baja', 'unidad' => $nombres[$c->persona_id]['unidad'] ?? null,
        ])->all();
    }
}
