<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Audit\BitacoraPlataforma;
use App\Core\Http\Exceptions\ApiException;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Caso de uso del super admin: inactivar (suspender) un condominio o reactivarlo. Suspender corta
 * el acceso de todos sus usuarios al instante (las membresías de un condominio suspendido no son
 * activas) pero no borra nada. Exige motivo y queda en la bitácora de plataforma. Al reactivar
 * vuelve al estado que tenía antes de suspenderlo.
 */
final class CambiarEstadoCondominioAction
{
    public function inactivar(int $id, string $motivo): Condominio
    {
        return DB::transaction(function () use ($id, $motivo): Condominio {
            $condominio = Condominio::query()->lockForUpdate()->findOrFail($id);

            if ($condominio->estado === Condominio::ESTADO_SUSPENDIDO) {
                throw new ApiException('CONDOMINIO_YA_INACTIVO', 'Este condominio ya está inactivo.', 409);
            }

            $antes = $condominio->estado;
            $condominio->forceFill(['estado' => Condominio::ESTADO_SUSPENDIDO, 'estado_antes_suspension' => $antes])->save();

            $this->registrar('inactivado', $condominio, $antes, Condominio::ESTADO_SUSPENDIDO, $motivo);

            return $condominio;
        });
    }

    public function reactivar(int $id, string $motivo): Condominio
    {
        return DB::transaction(function () use ($id, $motivo): Condominio {
            $condominio = Condominio::query()->lockForUpdate()->findOrFail($id);

            if ($condominio->estado !== Condominio::ESTADO_SUSPENDIDO) {
                throw new ApiException('CONDOMINIO_NO_INACTIVO', 'Este condominio no está inactivo.', 409);
            }

            $nuevo = $condominio->estado_antes_suspension ?? Condominio::ESTADO_ACTIVO;
            $condominio->forceFill(['estado' => $nuevo, 'estado_antes_suspension' => null])->save();

            $this->registrar('reactivado', $condominio, Condominio::ESTADO_SUSPENDIDO, $nuevo, $motivo);

            return $condominio;
        });
    }

    private function registrar(string $evento, Condominio $c, string $antes, string $despues, string $motivo): void
    {
        BitacoraPlataforma::registrar('actualizado', 'condominio', $c->id, $c->nombre, ['estado' => $antes], ['estado' => $despues, 'accion' => $evento, 'motivo' => $motivo], $c->id);
        Log::warning("seguridad.condominio_{$evento}", ['condominio_id' => $c->id, 'motivo' => $motivo, 'ambito' => 'plataforma']);
    }
}
