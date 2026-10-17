<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Finanzas\Services\DestinatariosFinanzas;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Reservas\Mail\DanosEnAreaMail;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Caso de uso del guardia: recibir el área al terminar el uso. Si reporta daños, se guardan en la reserva y se avisa a la
 * administración para que decida si descuenta de la garantía o los cobra a la cuenta de la unidad.
 */
final class RecibirAreaAction
{
    public function __construct(private readonly DestinatariosFinanzas $destinatarios) {}

    public function execute(User $guardia, int $reservaId, ?string $danos): Reserva
    {
        $reserva = DB::transaction(function () use ($guardia, $reservaId, $danos): Reserva {
            $reserva = Reserva::query()->lockForUpdate()->findOrFail($reservaId);
            if ($reserva->estado !== Reserva::EN_USO) {
                throw new ApiException('RESERVA_NO_EN_USO', 'Solo se recibe un área que está en uso.', 409);
            }
            $reserva->forceFill(['estado' => Reserva::FINALIZADA, 'entrega_en' => now(), 'entrega_por' => $guardia->id, 'danos' => $danos])->save();

            return $reserva;
        });

        if ($danos !== null) {
            $correos = $this->destinatarios->correos();
            if ($correos !== []) {
                Mail::to($correos)->send(new DanosEnAreaMail(
                    (string) (Condominio::query()->whereKey($reserva->condominio_id)->value('nombre') ?? 'tu condominio'),
                    (string) (CondominioAmenidad::query()->whereKey($reserva->amenidad_id)->value('nombre') ?? 'Área común'),
                    (string) (Unidad::query()->whereKey($reserva->unidad_id)->value('codigo') ?? ''),
                    $reserva->fecha->format('d/m/Y').' · '.substr($reserva->inicio, 0, 5).' a '.substr($reserva->fin, 0, 5),
                    $danos,
                ));
            }
        }

        return $reserva;
    }
}
