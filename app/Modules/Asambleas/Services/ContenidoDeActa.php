<?php

namespace App\Modules\Asambleas\Services;

use App\Core\Permissions\Rol;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Asambleas\Actions\VerVotacionAction;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaAsistencia;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\AsambleaUnidad;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Comunicacion\Models\AnuncioDestinatario;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Actions\ResumenPersonasAction;
use App\Modules\Usuarios\Models\CargoDirectiva;
use Carbon\CarbonImmutable;

/**
 * Arma la foto del acta con lo que pasó en la asamblea: convocatoria, instalación, asistencia, poderes, resultados por punto y
 * resoluciones. Se guarda al cerrar y no cambia aunque después cambien los datos (nombres, alícuotas, mora).
 */
final class ContenidoDeActa
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Calendario $calendario,
        private readonly VerVotacionAction $votacion,
        private readonly ResumenPersonasAction $personas,
    ) {}

    /** Puntos votables o de elección que no se trataron (el cierre pide confirmarlo). @return list<array{orden: int, titulo: string}> */
    public function pendientes(Asamblea $a): array
    {
        $a->loadMissing('puntos');
        $pend = [];
        foreach ($a->puntos as $p) {
            if ($p->seVota() && $this->estadoDe($p) === 'no_tratado') {
                $pend[] = ['orden' => $p->orden, 'titulo' => $p->titulo];
            }
        }

        return $pend;
    }

    /**
     * @return array<string, mixed>
     */
    public function armar(Asamblea $a, CarbonImmutable $cerradaEn, ?User $cerradaPor): array
    {
        $a->load('puntos');
        $zona = $this->calendario->zonaHoraria();
        $local = fn (?\DateTimeInterface $d, string $f = 'Y-m-d\TH:i'): ?string => $d === null ? null : CarbonImmutable::instance($d)->setTimezone($zona)->format($f);
        $condominio = Condominio::query()->findOrFail($this->tenant->require());
        $foto = AsambleaUnidad::query()->where('asamblea_id', $a->id)->get()->keyBy('unidad_id');

        $asistencias = AsambleaAsistencia::query()->where('asamblea_id', $a->id)->orderBy('ingreso_en')->get();
        $poderes = AsambleaPoder::query()->where('asamblea_id', $a->id)->where('estado', AsambleaPoder::VALIDADO)->get();
        $nombresPoder = $this->personas->execute($poderes->pluck('otorgante_persona_id')->filter()->unique()->all());
        $cargos = CargoDirectiva::query()->whereNull('cerrado_en')->whereIn('cargo', [Rol::Presidente->value, Rol::Secretario->value])->get()->keyBy('cargo');
        $nombresCargo = $this->personas->execute($cargos->pluck('persona_id')->all());
        $anuncio = $a->anuncio_id === null ? collect() : AnuncioDestinatario::query()->where('anuncio_id', $a->anuncio_id)->get();

        $total = (float) $a->quorum_alicuota_total;
        $puntos = $a->puntos->map(fn (AsambleaPunto $p): array => $this->punto($p))->all();

        return [
            'condominio' => ['nombre' => $condominio->nombre, 'ruc' => $condominio->ruc, 'direccion' => $condominio->direccion],
            'asamblea' => [
                'titulo' => $a->titulo, 'tipo' => $a->tipo, 'modalidad' => $a->modalidad, 'fecha_hora' => $local($a->fecha_hora),
                'lugar' => $a->lugar, 'enlace_video' => $a->enlace_video,
            ],
            'convocatoria' => [
                'convocada_en' => $local($a->convocada_en), 'unidades' => $anuncio->count(), 'confirmaron' => $anuncio->whereNotNull('confirmado_en')->count(),
            ],
            'instalacion' => [
                'instalada_en' => $local($a->instalada_en), 'convocatoria' => $a->convocatoria, 'en_subrogacion' => $a->en_subrogacion,
                'instalada_por' => $a->instalada_por === null ? null : User::query()->whereKey($a->instalada_por)->value('name'),
                'quorum_alicuota_presente' => (string) $a->quorum_alicuota_presente, 'quorum_alicuota_total' => (string) $a->quorum_alicuota_total,
                'quorum_pct' => $total > 0 ? number_format((float) $a->quorum_alicuota_presente * 100 / $total, 2, '.', '') : '0.00',
                'mora_cuenta_para_quorum' => $a->mora_cuenta_para_quorum,
            ],
            'cierre' => ['cerrada_en' => $local($cerradaEn), 'cerrada_por' => $cerradaPor?->name],
            'directiva' => [
                'presidente' => isset($cargos['presidente']) ? ($nombresCargo[$cargos['presidente']->persona_id]['nombre'] ?? null) : null,
                'secretario' => isset($cargos['secretario']) ? ($nombresCargo[$cargos['secretario']->persona_id]['nombre'] ?? null) : null,
            ],
            'padron' => [
                'unidades' => $foto->count(), 'en_mora' => $foto->where('en_mora', true)->map(fn (AsambleaUnidad $u): string => $u->codigo)->sort(SORT_NATURAL)->values()->all(),
            ],
            'asistencia' => $asistencias->map(fn (AsambleaAsistencia $x): array => [
                'codigo' => $foto[$x->unidad_id]->codigo ?? '—', 'alicuota' => (string) ($foto[$x->unidad_id]->alicuota ?? '0'),
                'asistente' => $x->asistente_nombre, 'forma' => $x->forma, 'ingreso_en' => $local($x->ingreso_en), 'salida_en' => $local($x->salida_en),
                'en_mora' => (bool) ($foto[$x->unidad_id]->en_mora ?? false),
            ])->all(),
            'poderes' => $poderes->map(fn (AsambleaPoder $p): array => [
                'codigo' => $foto[$p->unidad_id]->codigo ?? '—', 'otorgante' => $nombresPoder[$p->otorgante_persona_id ?? 0]['nombre'] ?? null,
                'representante' => $p->representante_nombre, 'tipo' => $p->tipo,
            ])->all(),
            'puntos' => $puntos,
            'resoluciones' => $this->resoluciones($puntos),
        ];
    }

    /** Cómo quedó un punto: tratado (informativo), votado o sin tratar. */
    private function estadoDe(AsambleaPunto $p): string
    {
        if (! $p->seVota()) {
            return 'tratado';
        }
        if ($p->tipo === AsambleaPunto::ELECCION) {
            return $p->eleccion_cerrada_en !== null ? 'votado' : 'no_tratado';
        }

        return Votacion::query()->where('punto_id', $p->id)->where('estado', Votacion::CERRADA)->exists() ? 'votado' : 'no_tratado';
    }

    /** @return array<string, mixed> */
    private function punto(AsambleaPunto $p): array
    {
        $estado = $this->estadoDe($p);
        $votaciones = Votacion::query()->where('punto_id', $p->id)->where('estado', Votacion::CERRADA)->orderBy('id')->get();

        return [
            'id' => $p->id, 'orden' => $p->orden, 'titulo' => $p->titulo, 'descripcion' => $p->descripcion, 'tipo' => $p->tipo, 'estado' => $estado,
            'mayoria' => $p->mayoria, 'umbral_pct' => $p->umbral_pct === null ? null : (string) $p->umbral_pct,
            'votaciones' => $estado === 'votado' ? $votaciones->map(fn (Votacion $v): array => $this->votacion->execute($v))->all() : [],
            'nombramientos' => $p->nombramientos,
        ];
    }

    /**
     * Lo aprobado: un punto de votación aprobado y cada cargo de directiva nombrado.
     *
     * @param  list<array<string, mixed>>  $puntos
     * @return list<array{punto_id: int|null, texto: string}>
     */
    private function resoluciones(array $puntos): array
    {
        $r = [];
        foreach ($puntos as $p) {
            if ($p['tipo'] === AsambleaPunto::ELECCION) {
                foreach ($p['nombramientos'] ?? [] as $n) {
                    if (($n['estado'] ?? null) === 'nombrado') {
                        $r[] = ['punto_id' => $p['id'], 'texto' => "Se eligió a {$n['nombre']} como {$n['etiqueta']} de la directiva."];
                    }
                }

                continue;
            }
            $v = $p['votaciones'][0] ?? null;
            if ($v !== null && $v['resultado'] === Votacion::APROBADO) {
                $r[] = ['punto_id' => $p['id'], 'texto' => 'Se aprobó: '.$p['titulo'].'.'.($p['descripcion'] ? ' '.$p['descripcion'] : '')];
            }
        }

        return $r;
    }
}
