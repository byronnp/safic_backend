<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta para el propietario: las asambleas convocadas o en curso, con sus unidades (solo vota el propietario, Art. 41), el poder
 * que dio por cada una y las unidades que representa por poder de otros.
 */
final class MisAsambleasAction
{
    public function __construct(private readonly Calendario $calendario, private readonly UnidadesDeCuentaAction $unidades) {}

    /** @return list<array<string, mixed>> */
    public function execute(User $user): array
    {
        $propias = collect($this->unidades->execute($user->id))->where('relacion', 'propietario')->values();
        $personas = Persona::query()->where('user_id', $user->id)->pluck('id')->all();
        $asambleas = Asamblea::query()->whereIn('estado', [Asamblea::CONVOCADA, Asamblea::EN_REGISTRO, Asamblea::INSTALADA, Asamblea::EN_VOTACION])->orderBy('fecha_hora')->get();
        $poderes = AsambleaPoder::query()->whereIn('asamblea_id', $asambleas->pluck('id'))->where('estado', '!=', AsambleaPoder::REVOCADO)->get()->groupBy('asamblea_id');
        $alicuotas = Unidad::query()->whereIn('id', $propias->pluck('unidad_id'))->pluck('alicuota', 'id');
        $codigos = Unidad::query()->withTrashed()->whereIn('id', $poderes->flatten()->pluck('unidad_id'))->pluck('codigo', 'id');

        return $asambleas->map(function (Asamblea $a) use ($propias, $poderes, $alicuotas, $codigos, $personas): array {
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
            ];
        })->values()->all();
    }
}
