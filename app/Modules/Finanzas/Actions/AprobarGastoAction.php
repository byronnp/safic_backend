<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Permiso;
use App\Core\Permissions\Rol;
use App\Core\Support\Dinero;
use App\Models\User;
use App\Modules\Finanzas\Models\ConfiguracionFinanzas;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\GastoAprobacion;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: aprobar o rechazar una factura de proveedor. Dos niveles: el 1.º lo da la
 * administración; si el total supera el umbral del condominio, el 2.º lo da la directiva
 * (presidente o vicepresidente). Quien registró la factura no la aprueba ni la rechaza, y la
 * misma persona no da los dos niveles. Si el vicepresidente da el 2.º nivel se marca como
 * subrogación. Rechazar exige motivo y cierra la factura.
 */
final class AprobarGastoAction
{
    public function aprobar(User $user, int $gastoId, ?string $comentario): Gasto
    {
        return DB::transaction(function () use ($user, $gastoId, $comentario): Gasto {
            [$gasto, $nivel] = $this->pendiente($user, $gastoId);

            $this->registrar($gasto, $nivel, 'aprobada', $user, $comentario);

            $umbral = Dinero::centavos(ConfiguracionFinanzas::vigente()->umbral_segunda_aprobacion);
            $gasto->nivel_aprobado = $nivel;
            if ($nivel === 2 || Dinero::centavos($gasto->total) <= $umbral) {
                $gasto->estado = 'aprobada';
            }
            $gasto->save();

            return $gasto;
        });
    }

    public function rechazar(User $user, int $gastoId, string $motivo): Gasto
    {
        return DB::transaction(function () use ($user, $gastoId, $motivo): Gasto {
            [$gasto, $nivel] = $this->pendiente($user, $gastoId);

            $this->registrar($gasto, $nivel, 'rechazada', $user, $motivo);

            $gasto->forceFill(['estado' => 'rechazada', 'motivo_rechazo' => $motivo])->save();

            return $gasto;
        });
    }

    /**
     * La factura bloqueada, pendiente, y el nivel que le toca a esta persona.
     *
     * @return array{0: Gasto, 1: int}
     */
    private function pendiente(User $user, int $gastoId): array
    {
        $gasto = Gasto::query()->lockForUpdate()->findOrFail($gastoId);

        if ($gasto->estado !== 'por_aprobar') {
            throw new ApiException('GASTO_NO_PENDIENTE', 'Esta factura ya no está por aprobar.', 409);
        }

        if ($gasto->registrado_por === $user->id) {
            throw new ApiException('REGISTRO_PROPIO', 'Quien registra una factura no puede aprobarla ni rechazarla.', 403);
        }

        $nivel = $gasto->nivel_aprobado + 1;
        $permiso = $nivel === 1 ? Permiso::GastosAprobarN1 : Permiso::GastosAprobarN2;
        if (! $user->can($permiso->value)) {
            throw new ApiException('NIVEL_NO_PERMITIDO', $nivel === 1
                ? 'La primera aprobación la da la administración.'
                : 'Esta factura supera el umbral: la segunda aprobación la da el presidente o el vicepresidente.', 403);
        }

        if (GastoAprobacion::query()->where('gasto_id', $gasto->id)->where('user_id', $user->id)->exists()) {
            throw new ApiException('MISMA_PERSONA', 'Ya diste la primera aprobación: la segunda la debe dar otra persona.', 403);
        }

        return [$gasto, $nivel];
    }

    private function registrar(Gasto $gasto, int $nivel, string $decision, User $user, ?string $comentario): void
    {
        GastoAprobacion::create([
            'gasto_id' => $gasto->id, 'nivel' => $nivel, 'decision' => $decision, 'user_id' => $user->id,
            'comentario' => $comentario !== null && trim($comentario) !== '' ? trim($comentario) : null,
            'en_subrogacion' => $nivel === 2 && $user->hasRole(Rol::Vicepresidente->value),
        ]);
    }
}
