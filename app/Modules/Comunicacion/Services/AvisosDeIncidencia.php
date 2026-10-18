<?php

namespace App\Modules\Comunicacion\Services;

use App\Models\User;
use App\Modules\Comunicacion\Mail\IncidenciaMail;
use App\Modules\Comunicacion\Models\Incidencia;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Support\Facades\Mail;

/**
 * Correo a quien reportó la incidencia cuando la administración le responde o cambia su estado. Si no tiene correo, no se avisa.
 */
final class AvisosDeIncidencia
{
    public function avisar(Incidencia $i, string $titulo, string $detalle): void
    {
        $user = $i->reportado_por === null ? null : User::query()->find($i->reportado_por);
        if ($user === null || $user->email === '') {
            return;
        }
        $condominio = (string) (Condominio::query()->whereKey($i->condominio_id)->value('nombre') ?? 'tu condominio');

        Mail::to($user->email)->send(new IncidenciaMail($user->name, $condominio, $titulo, "#{$i->numero} · {$i->titulo}", $detalle));
    }
}
