<?php

namespace App\Modules\Garita\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Garita\Models\SolicitudIngreso;
use App\Modules\Garita\Services\AvisosEnVivoDeGarita;
use App\Modules\Unidades\Actions\OcupantesVigentesAction;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del guardia: preguntarle al residente desde la app si deja pasar a quien llegó sin anunciarse. Solo tiene sentido si
 * alguien de la unidad tiene cuenta (si no, no hay a quién preguntarle y el guardia llama por teléfono). Una sola pregunta abierta por
 * unidad y persona a la vez.
 */
final class PedirPermisoIngresoAction
{
    public function __construct(private readonly OcupantesVigentesAction $ocupantes, private readonly AvisosEnVivoDeGarita $enVivo) {}

    public function execute(User $guardia, string $unidadCodigo, string $nombre, string $tipo, ?string $placa): SolicitudIngreso
    {
        $unidad = Unidad::query()->whereRaw('lower(codigo) = ?', [mb_strtolower(trim($unidadCodigo))])->first()
            ?? throw new ApiException('UNIDAD_NO_ENCONTRADA', 'No existe una unidad con ese código.', 422);

        if (! collect($this->ocupantes->conCuenta($unidad->id))->isNotEmpty()) {
            throw new ApiException('SIN_RESIDENTES_CON_CUENTA', 'Nadie de esa unidad usa la app. Llama por teléfono y registra el ingreso sin QR.', 422);
        }

        return DB::transaction(function () use ($guardia, $unidad, $nombre, $tipo, $placa): SolicitudIngreso {
            $abierta = SolicitudIngreso::query()->where('unidad_id', $unidad->id)->where('estado', SolicitudIngreso::PENDIENTE)
                ->where('expira_en', '>', now())->whereRaw('lower(visitante_nombre) = ?', [mb_strtolower($nombre)])->lockForUpdate()->exists();
            if ($abierta) {
                throw new ApiException('SOLICITUD_ABIERTA', 'Ya le preguntaste a esa unidad por esta persona. Espera su respuesta.', 409);
            }

            $solicitud = SolicitudIngreso::create([
                'unidad_id' => $unidad->id, 'visitante_nombre' => $nombre, 'tipo' => $tipo, 'placa' => $placa === null || $placa === '' ? null : strtoupper($placa),
                'creada_por' => $guardia->id, 'estado' => SolicitudIngreso::PENDIENTE, 'expira_en' => now()->addMinutes(SolicitudIngreso::MINUTOS_PARA_RESPONDER),
            ]);
            $this->enVivo->aLaUnidad($solicitud->condominio_id, $unidad->id, AvisosEnVivoDeGarita::SOLICITUDES, $solicitud->id);

            return $solicitud;
        });
    }
}
