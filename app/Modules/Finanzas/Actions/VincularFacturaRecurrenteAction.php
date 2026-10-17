<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Modules\Finanzas\Models\ConfiguracionFinanzas;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\GastoAprobacion;
use App\Modules\Finanzas\Models\GastoRecurrente;
use App\Modules\Finanzas\Models\RecurrenteOcurrencia;
use App\Modules\Finanzas\Services\PeriodoAbierto;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: decir que una factura registrada es la de un gasto recurrente en un mes. Debe ser del
 * mismo proveedor y no estar vinculada a otra. Si el recurrente tiene aprobación automática y la
 * factura coincide con su monto, el nivel 1 se da solo (queda el registro, sin persona); sobre el
 * umbral sigue faltando la segunda aprobación de la directiva.
 */
final class VincularFacturaRecurrenteAction
{
    public function __construct(private readonly PeriodoAbierto $periodoAbierto) {}

    /**
     * @param  string  $periodo  YYYY-MM
     * @return array{aprobada_automaticamente: bool}
     */
    public function execute(int $recurrenteId, string $periodo, int $gastoId): array
    {
        $mes = CarbonImmutable::createFromFormat('!Y-m', $periodo) ?: throw new ApiException('PERIODO_INVALIDO', 'El mes no es válido.', 422);

        try {
            return DB::transaction(function () use ($recurrenteId, $mes, $gastoId): array {
                $r = GastoRecurrente::query()->findOrFail($recurrenteId);
                $o = RecurrenteOcurrencia::query()->where('gasto_recurrente_id', $r->id)->where('periodo', $mes->toDateString())->lockForUpdate()->first()
                    ?? throw new ApiException('AUN_NO_SE_CREA', 'Este gasto todavía no se crea en ese mes: no hay a qué vincular la factura.', 409);

                if ($o->gasto_id !== null) {
                    throw new ApiException('YA_TIENE_FACTURA', 'Este gasto ya tiene su factura en ese mes.', 409);
                }

                $g = Gasto::query()->lockForUpdate()->findOrFail($gastoId);
                if ($g->proveedor_id !== $r->proveedor_id) {
                    throw new ApiException('PROVEEDOR_DISTINTO', 'La factura es de otro proveedor.', 422);
                }
                $this->periodoAbierto->exigir($g->fecha_emision->toDateString());

                $auto = $this->aprobarSiCorresponde($r, $g);
                $o->forceFill(['gasto_id' => $g->id, 'aprobada_automaticamente' => $auto])->save();

                return ['aprobada_automaticamente' => $auto];
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('FACTURA_YA_VINCULADA', 'Esa factura ya está vinculada a otro gasto recurrente.', 409);
        }
    }

    private function aprobarSiCorresponde(GastoRecurrente $r, Gasto $g): bool
    {
        if (! $r->aprobacion_automatica || $r->monto === null || $g->estado !== 'por_aprobar' || $g->nivel_aprobado !== 0) {
            return false;
        }
        if (Dinero::centavos((string) $g->total) !== Dinero::centavos((string) $r->monto)) {
            return false;
        }

        GastoAprobacion::create([
            'gasto_id' => $g->id, 'nivel' => 1, 'decision' => 'aprobada', 'user_id' => null,
            'comentario' => "Aprobación automática: coincide con el gasto recurrente «{$r->nombre}»", 'en_subrogacion' => false,
        ]);

        $umbral = Dinero::centavos(ConfiguracionFinanzas::vigente()->umbral_segunda_aprobacion);
        $g->nivel_aprobado = 1;
        if (Dinero::centavos((string) $g->total) <= $umbral) {
            $g->estado = 'aprobada';
        }
        $g->save();

        return true;
    }
}
