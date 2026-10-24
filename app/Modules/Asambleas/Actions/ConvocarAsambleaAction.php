<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Services\AnticipacionDeConvocatoria;
use App\Modules\Asambleas\Services\MensajeDeConvocatoria;
use App\Modules\Comunicacion\Actions\PublicarAnuncioAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso de la administración o la directiva: convocar la asamblea. Exige orden del día y los 8 días hábiles de anticipación
 * (Art. 37). Se publica como anuncio fijado y con confirmación de lectura a todo el condominio (Fase 4): la constancia de
 * recepción por unidad queda como respaldo legal, y expira el día de la asamblea.
 */
final class ConvocarAsambleaAction
{
    public function __construct(
        private readonly PublicarAnuncioAction $anuncios,
        private readonly AnticipacionDeConvocatoria $anticipacion,
        private readonly MensajeDeConvocatoria $mensaje,
        private readonly Calendario $calendario,
    ) {}

    public function execute(User $user, int $id): Asamblea
    {
        return DB::transaction(function () use ($user, $id): Asamblea {
            $a = Asamblea::query()->with('puntos')->lockForUpdate()->findOrFail($id);
            if (! $a->esBorrador()) {
                throw new ApiException('ASAMBLEA_YA_CONVOCADA', 'Esta asamblea ya se convocó.', 409);
            }
            if ($a->puntos->isEmpty()) {
                throw new ApiException('SIN_ORDEN_DEL_DIA', 'Agrega al menos un punto al orden del día antes de convocar.', 422);
            }

            $antic = $this->anticipacion->de($a->fecha_hora);
            if (! $antic['cumple']) {
                throw new ApiException('SIN_ANTICIPACION', "Ya no hay {$antic['minimo']} días hábiles de anticipación (Art. 37): había que convocar a más tardar el {$antic['ultimo_dia']}. Cambia la fecha de la asamblea.", 422);
            }

            $anuncio = $this->anuncios->execute($user, [
                'titulo' => $this->mensaje->titulo($a), 'mensaje' => $this->mensaje->texto($a), 'destino_tipo' => 'todos',
                'fijar' => true, 'confirmacion' => true,
                'expira' => CarbonImmutable::instance($a->fecha_hora)->setTimezone($this->calendario->zonaHoraria())->toDateString(),
            ], null);

            $a->forceFill(['estado' => Asamblea::CONVOCADA, 'convocada_por' => $user->id, 'convocada_en' => now(), 'anuncio_id' => $anuncio->id])->save();

            return $a;
        });
    }
}
