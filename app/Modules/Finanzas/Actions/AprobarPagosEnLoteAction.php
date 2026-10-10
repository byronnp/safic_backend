<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Models\User;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Services\AplicacionPagoService;
use App\Modules\Finanzas\Services\ToleranciaBancaria;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Caso de uso de la administración: aprobar varios pagos con lo declarado. Cada uno es su propia
 * transacción (si uno falla, los demás se aprueban). Solo pasan los que superan todas las
 * validaciones; el resto queda pendiente para revisarlo uno por uno.
 */
final class AprobarPagosEnLoteAction
{
    public function __construct(
        private readonly AprobarPagoAction $aprobar,
        private readonly VerPagoAction $ver,
        private readonly AplicacionPagoService $aplicacion,
        private readonly ToleranciaBancaria $tolerancia,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, ok: bool, codigo: string|null, mensaje: string|null}>
     */
    public function execute(array $ids, User $actor): array
    {
        $resultados = [];

        foreach (array_unique($ids) as $id) {
            try {
                $pago = Pago::query()->findOrFail($id);
                if ($pago->estado === Pago::PENDIENTE) {
                    $simulacion = $this->aplicacion->simular($pago, Dinero::centavos($pago->monto), $this->tolerancia->centavos());
                    $fallidas = array_filter($this->ver->validaciones($pago, $simulacion), fn (array $v) => ! $v['ok']);
                    if ($fallidas !== []) {
                        throw new ApiException('VALIDACION_FALLIDA', 'No pasa las validaciones: revísalo uno por uno.', 422);
                    }
                }
                $this->aprobar->execute($id, null, $actor);
                $resultados[] = ['id' => $id, 'ok' => true, 'codigo' => null, 'mensaje' => null];
            } catch (ApiException $e) {
                $resultados[] = ['id' => $id, 'ok' => false, 'codigo' => $e->errorCode, 'mensaje' => $e->getMessage()];
            } catch (ModelNotFoundException) {
                $resultados[] = ['id' => $id, 'ok' => false, 'codigo' => 'PAGO_NO_ENCONTRADO', 'mensaje' => 'Ese pago no existe.'];
            }
        }

        return $resultados;
    }
}
