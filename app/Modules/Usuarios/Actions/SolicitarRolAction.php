<?php

namespace App\Modules\Usuarios\Actions;

use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Usuarios\Mail\SolicitudRolMail;
use App\Modules\Usuarios\Models\SolicitudRol;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Caso de uso: el administrador le pide a la plataforma un rol que no existe. Queda
 * registrado y, si hay correo de soporte configurado, se le avisa.
 */
final class SolicitarRolAction
{
    public function execute(string $nombre, string $descripcion, User $solicitante): SolicitudRol
    {
        return DB::transaction(function () use ($nombre, $descripcion, $solicitante): SolicitudRol {
            $solicitud = SolicitudRol::create([
                'user_id' => $solicitante->id,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
            ]);

            $soporte = config('safic.soporte_email');
            if (is_string($soporte) && $soporte !== '') {
                Mail::to($soporte)->queue(new SolicitudRolMail(
                    condominio: Condominio::query()->findOrFail($solicitud->condominio_id)->nombre,
                    solicitante: $solicitante->name,
                    nombre: $nombre,
                    descripcion: $descripcion,
                ));
            }

            return $solicitud;
        });
    }
}
