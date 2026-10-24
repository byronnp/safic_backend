<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Plataforma\Models\Condominio;

/**
 * La mesa de la asamblea: lo que ve el presidente y se proyecta en la sala. Quórum en vivo, cómo se instaló y el orden del día.
 * Las votaciones por punto llegan con su entrega.
 */
final class VerMesaAction
{
    public function __construct(
        private readonly CalcularQuorumAction $quorum,
        private readonly Calendario $calendario,
        private readonly VerVotacionAction $votacion,
    ) {}

    /** @return array<string, mixed> */
    public function execute(int $id): array
    {
        $a = Asamblea::query()->with('puntos')->findOrFail($id);
        $zona = $this->calendario->zonaHoraria();
        $votaciones = Votacion::query()->where('asamblea_id', $a->id)->get()->keyBy('punto_id');
        $abierta = $votaciones->first(fn (Votacion $v): bool => $v->estaAbierta());
        $instaladaPor = $a->instalada_por === null ? null : User::query()->whereKey($a->instalada_por)->value('name');

        return [
            'id' => $a->id, 'titulo' => $a->titulo, 'tipo' => $a->tipo, 'modalidad' => $a->modalidad, 'estado' => $a->estado,
            'condominio' => (string) Condominio::query()->whereKey($a->condominio_id)->value('nombre'),
            'fecha_hora' => $a->fecha_hora->setTimezone($zona)->format('Y-m-d\TH:i'),
            'lugar' => $a->lugar, 'enlace_video' => $a->enlace_video,
            'quorum' => $this->quorum->execute($a),
            'instalacion' => $a->instalada_en === null ? null : [
                'instalada_en' => $a->instalada_en->setTimezone($zona)->format('Y-m-d\TH:i'),
                'convocatoria' => $a->convocatoria, 'en_subrogacion' => $a->en_subrogacion, 'instalada_por' => $instaladaPor,
                'quorum_alicuota_presente' => (string) $a->quorum_alicuota_presente, 'quorum_alicuota_total' => (string) $a->quorum_alicuota_total,
            ],
            'puntos' => $a->puntos->map(fn (AsambleaPunto $p): array => [
                'id' => $p->id, 'orden' => $p->orden, 'titulo' => $p->titulo, 'tipo' => $p->tipo, 'mayoria' => $p->mayoria,
                'umbral_pct' => $p->umbral_pct === null ? null : (string) $p->umbral_pct, 'base' => $p->base,
                'votacion' => ($v = $votaciones->get($p->id)) === null ? null : ['id' => $v->id, 'estado' => $v->estado, 'resultado' => $v->resultado],
                // Instalada y sin votación abierta, el presidente puede abrir los puntos que se votan y aún no se votaron
                'puede_abrir_votacion' => $a->estado === Asamblea::INSTALADA && $p->seVota() && $p->tipo !== AsambleaPunto::ELECCION && $v === null,
            ])->all(),
            'votacion_abierta' => $abierta === null ? null : $this->votacion->execute($abierta),
            'puede_instalar' => $a->estado === Asamblea::EN_REGISTRO,
        ];
    }
}
