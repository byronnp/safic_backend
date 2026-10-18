<?php

namespace App\Modules\Garita\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Models\SolicitudIngreso;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del guardia: dejar pasar a quien el residente permitió desde la app. Registra el ingreso (autorizado por «app_residente»)
 * y cierra la solicitud. La autorización vale 15 minutos desde que el residente la dio.
 */
final class RegistrarIngresoDeSolicitudAction
{
    public const MINUTOS_DE_VIGENCIA = 15;

    public function __construct(private readonly RegistrarAccesoAction $registrar) {}

    public function execute(User $guardia, int $id): AccesoGarita
    {
        return DB::transaction(function () use ($guardia, $id): AccesoGarita {
            $s = SolicitudIngreso::query()->lockForUpdate()->findOrFail($id);
            if ($s->estado !== SolicitudIngreso::PERMITIDA) {
                throw new ApiException('SOLICITUD_NO_PERMITIDA', 'El residente no ha permitido este ingreso.', 409);
            }
            if ($s->respondida_en === null || $s->respondida_en->addMinutes(self::MINUTOS_DE_VIGENCIA)->isPast()) {
                throw new ApiException('SOLICITUD_VENCIDA', 'La autorización del residente ya venció. Pregunta de nuevo.', 409);
            }

            $acceso = $this->registrar->execute($guardia, [
                'tipo' => $s->tipo, 'nombre' => $s->visitante_nombre, 'placa' => $s->placa,
                'unidad_codigo' => Unidad::query()->whereKey($s->unidad_id)->value('codigo'), 'autorizado_por' => 'app_residente',
            ]);
            $s->forceFill(['estado' => SolicitudIngreso::USADA, 'acceso_id' => $acceso->id])->save();

            return $acceso;
        });
    }
}
