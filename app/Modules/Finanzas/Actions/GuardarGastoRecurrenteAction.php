<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Finanzas\Models\GastoRecurrente;
use App\Modules\Finanzas\Models\Proveedor;
use App\Modules\Finanzas\Services\RecurrentesService;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: crear o editar un gasto recurrente. Sin monto es variable (luz, agua) y no admite
 * aprobación automática (no hay monto contra el cual comparar la factura). Si al crearlo o
 * reactivarlo ya pasó su día de este mes, se crea de inmediato la ocurrencia del mes.
 */
final class GuardarGastoRecurrenteAction
{
    public function __construct(private readonly RecurrentesService $recurrentes) {}

    /**
     * @param  array<string, mixed>  $datos  Validados por el controlador
     */
    public function crear(array $datos): GastoRecurrente
    {
        $this->validar($datos);

        return DB::transaction(function () use ($datos): GastoRecurrente {
            $r = GastoRecurrente::create($this->campos($datos) + ['activo' => true]);
            $this->recurrentes->generar($r);

            return $r;
        });
    }

    /**
     * @param  array<string, mixed>  $datos  Solo lo que cambia
     */
    public function editar(int $id, array $datos): GastoRecurrente
    {
        return DB::transaction(function () use ($id, $datos): GastoRecurrente {
            $r = GastoRecurrente::query()->lockForUpdate()->findOrFail($id);
            $this->validar($datos + ['monto' => $r->monto, 'aprobacion_automatica' => $r->aprobacion_automatica]);

            $r->fill($this->campos($datos));
            if (array_key_exists('activo', $datos)) {
                $r->activo = (bool) $datos['activo'];
            }
            $r->save();

            if ($r->activo) {
                $this->recurrentes->generar($r);
            }

            return $r;
        });
    }

    /** @param  array<string, mixed>  $datos */
    private function validar(array $datos): void
    {
        if (isset($datos['proveedor_id']) && ! Proveedor::query()->where('activo', true)->whereKey($datos['proveedor_id'])->exists()) {
            throw new ApiException('PROVEEDOR_INVALIDO', 'Elige un proveedor de este condominio.', 422);
        }
        if (($datos['aprobacion_automatica'] ?? false) && ($datos['monto'] ?? null) === null) {
            throw new ApiException('APROBACION_SIN_MONTO', 'La aprobación automática necesita un monto fijo: sin monto no hay con qué comparar la factura.', 422);
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function campos(array $datos): array
    {
        return array_intersect_key($datos, array_flip(['nombre', 'proveedor_id', 'categoria', 'monto', 'dia_mes', 'aprobacion_automatica']));
    }
}
