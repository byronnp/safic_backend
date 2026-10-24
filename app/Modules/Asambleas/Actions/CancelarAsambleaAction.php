<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Comunicacion\Actions\PublicarAnuncioAction;
use App\Modules\Comunicacion\Models\Anuncio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: cancelar una asamblea ya convocada y todavía sin instalar. La convocatoria deja de estar vigente y se avisa a
 * todos con un anuncio informativo que lleva el motivo. Una asamblea instalada no se cancela: se cierra con su acta.
 */
final class CancelarAsambleaAction
{
    public function __construct(private readonly PublicarAnuncioAction $anuncios, private readonly Calendario $calendario) {}

    public function execute(User $user, int $id, string $motivo): Asamblea
    {
        return DB::transaction(function () use ($user, $id, $motivo): Asamblea {
            $a = Asamblea::query()->lockForUpdate()->findOrFail($id);
            if (! $a->estaConvocada()) {
                throw new ApiException('ASAMBLEA_NO_CANCELABLE', 'Solo se puede cancelar una asamblea convocada que todavía no se instaló.', 409);
            }

            $hoy = $this->calendario->hoy();
            if ($a->anuncio_id !== null) {
                Anuncio::query()->whereKey($a->anuncio_id)->update(['expira_en' => CarbonImmutable::parse($hoy)->subDay()->toDateString(), 'fijado' => false]);
            }

            $dia = CarbonImmutable::instance($a->fecha_hora)->setTimezone($this->calendario->zonaHoraria())->toDateString();
            $this->anuncios->execute($user, [
                'titulo' => mb_substr("Asamblea cancelada: {$a->titulo}", 0, 120),
                'mensaje' => "Se canceló la asamblea convocada para el {$dia}.\nMotivo: {$motivo}\nSe te avisará cuando haya una nueva convocatoria.",
                'destino_tipo' => 'todos', 'fijar' => true, 'confirmacion' => false, 'expira' => max($hoy, $dia),
            ], null);

            $a->forceFill(['estado' => Asamblea::CANCELADA, 'cancelada_en' => now(), 'motivo_cancelacion' => $motivo])->save();

            return $a;
        });
    }
}
