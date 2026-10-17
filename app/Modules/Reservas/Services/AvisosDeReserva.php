<?php

namespace App\Modules\Reservas\Services;

use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Reservas\Mail\ReservaActualizadaMail;
use App\Modules\Reservas\Models\Reserva;
use Illuminate\Support\Facades\Mail;

/**
 * Correo al residente que pidió la reserva cuando cambia su estado. Si no tiene correo o ya no existe, no se avisa.
 */
final class AvisosDeReserva
{
    public function avisar(Reserva $reserva, string $titulo, string $detalle): void
    {
        $user = $reserva->solicitante_id === null ? null : User::query()->find($reserva->solicitante_id);
        if ($user === null || $user->email === '') {
            return;
        }

        $area = CondominioAmenidad::query()->whereKey($reserva->amenidad_id)->value('nombre') ?? 'Área común';
        $condominio = Condominio::query()->whereKey($reserva->condominio_id)->value('nombre') ?? 'tu condominio';

        Mail::to($user->email)->send(new ReservaActualizadaMail(
            $user->name, (string) $condominio, $titulo, (string) $area,
            $reserva->fecha->format('d/m/Y').' · '.substr($reserva->inicio, 0, 5).' a '.substr($reserva->fin, 0, 5), $detalle,
        ));
    }
}
