<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Tenancy\Calendario;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaAsistencia;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Asambleas\Services\PadronDeLaAsamblea;

/**
 * Lista para la mesa: cada unidad del padrón con quién está presente, el poder validado que tenga y si debe cuotas, junto al quórum.
 * Es la pantalla del secretario para marcar asistencia.
 */
final class VerAsistenciaAction
{
    public function __construct(
        private readonly PadronDeLaAsamblea $padron,
        private readonly CalcularQuorumAction $quorum,
        private readonly Calendario $calendario,
    ) {}

    /** @return array<string, mixed> */
    public function execute(int $asambleaId): array
    {
        $asamblea = Asamblea::query()->findOrFail($asambleaId);
        $unidades = $this->padron->de($asamblea);
        $presentes = AsambleaAsistencia::query()->presentes()->where('asamblea_id', $asambleaId)->get()->keyBy('unidad_id');
        $poderes = AsambleaPoder::query()->where('asamblea_id', $asambleaId)->where('estado', AsambleaPoder::VALIDADO)->get()->keyBy('unidad_id');
        $zona = $this->calendario->zonaHoraria();

        $filas = array_map(function (array $u) use ($presentes, $poderes, $zona): array {
            /** @var AsambleaAsistencia|null $p */
            $p = $presentes->get($u['unidad_id']);
            /** @var AsambleaPoder|null $poder */
            $poder = $poderes->get($u['unidad_id']);

            return [
                'unidad_id' => $u['unidad_id'], 'codigo' => $u['codigo'], 'alicuota' => $u['alicuota'],
                'propietarios' => array_column($u['propietarios'], 'nombre'),
                'en_mora' => $u['en_mora'],
                'poder' => $poder === null ? null : ['id' => $poder->id, 'representante_nombre' => $poder->representante_nombre],
                'presente' => $p === null ? null : [
                    'asistente' => $p->asistente_nombre, 'forma' => $p->forma, 'canal' => $p->canal,
                    'ingreso_en' => $p->ingreso_en->setTimezone($zona)->format('Y-m-d\TH:i'),
                ],
            ];
        }, $unidades);

        return ['quorum' => $this->quorum->execute($asamblea), 'unidades' => $filas];
    }
}
