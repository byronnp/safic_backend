<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Banking\LectorEstadoCuenta;
use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\EstadoCuentaBancario;
use App\Modules\Finanzas\Models\MovimientoBancario;
use App\Modules\Finanzas\Services\ConciliacionService;
use App\Modules\Finanzas\Services\PeriodoAbierto;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: importar el estado de cuenta de un mes de una cuenta del condominio. Solo se
 * importan las líneas con fecha dentro del mes; las que ya estaban (misma fecha, referencia, monto y
 * descripción) se descartan, así que volver a subir el archivo no duplica nada. Cada línea nueva se
 * clasifica al importar. No se importa a un mes cerrado.
 */
final class ImportarEstadoCuentaAction
{
    public function __construct(
        private readonly LectorEstadoCuenta $lector,
        private readonly ConciliacionService $conciliador,
        private readonly PeriodoAbierto $periodoAbierto,
    ) {}

    /**
     * @return array{estado: EstadoCuentaBancario, nuevos: int, duplicados: int, fuera_del_mes: int}
     */
    public function execute(User $user, int $cuentaId, string $periodo, UploadedFile $archivo, ?string $saldoInicial, ?string $saldoFinal): array
    {
        $mes = CarbonImmutable::createFromFormat('!Y-m', $periodo) ?: throw new ApiException('PERIODO_INVALIDO', 'El mes no es válido.', 422);
        $this->periodoAbierto->exigir($mes->toDateString());

        CuentaBancaria::query()->where('activa', true)->find($cuentaId)
            ?? throw new ApiException('CUENTA_INVALIDA', 'Elige una cuenta bancaria del condominio.', 422);

        $leido = $this->lector->leer((string) $archivo->getRealPath(), $archivo->getClientOriginalExtension());

        $inicio = $mes->toDateString();
        $fin = $mes->endOfMonth()->toDateString();
        $delMes = array_values(array_filter($leido->movimientos, fn (array $m) => $m['fecha'] >= $inicio && $m['fecha'] <= $fin));
        $fuera = count($leido->movimientos) - count($delMes);
        if ($delMes === []) {
            throw new ApiException('ESTADO_FUERA_DEL_MES', 'El archivo no tiene movimientos de ese mes. Revisa el mes o el archivo.', 422);
        }

        return DB::transaction(function () use ($user, $cuentaId, $mes, $archivo, $saldoInicial, $saldoFinal, $leido, $delMes, $fuera): array {
            $estado = EstadoCuentaBancario::query()->where('cuenta_bancaria_id', $cuentaId)->where('periodo', $mes->toDateString())->lockForUpdate()->first()
                ?? new EstadoCuentaBancario(['cuenta_bancaria_id' => $cuentaId, 'periodo' => $mes->toDateString()]);

            $estado->fill([
                'archivo_nombre' => mb_substr($archivo->getClientOriginalName(), 0, 160),
                // Lo que el archivo dice; si no trae saldos, lo que escribió la persona
                'saldo_inicial' => $saldoInicial ?? $leido->saldoInicial ?? $estado->saldo_inicial,
                'saldo_final' => $saldoFinal ?? $leido->saldoFinal ?? $estado->saldo_final,
                'importado_por' => $user->id,
            ]);
            $estado->save();

            $huellas = MovimientoBancario::query()->where('cuenta_bancaria_id', $cuentaId)->pluck('huella')->flip();
            $nuevos = collect();
            $duplicados = 0;
            $vistas = [];
            foreach ($delMes as $m) {
                // Dos líneas idénticas el mismo día son dos movimientos: se numeran dentro del archivo
                $base = hash('sha256', implode('|', [$cuentaId, $m['fecha'], $m['referencia'] ?? '', $m['monto'], mb_strtoupper($m['descripcion'])]));
                $vistas[$base] = ($vistas[$base] ?? 0) + 1;
                $huella = $vistas[$base] === 1 ? $base : hash('sha256', $base.'#'.$vistas[$base]);

                if ($huellas->has($huella)) {
                    $duplicados++;

                    continue;
                }
                $nuevos->push(MovimientoBancario::create([
                    'estado_cuenta_id' => $estado->id, 'cuenta_bancaria_id' => $cuentaId, 'fecha' => $m['fecha'],
                    'descripcion' => $m['descripcion'], 'referencia' => $m['referencia'], 'monto' => $m['monto'], 'saldo' => $m['saldo'],
                    'huella' => $huella, 'estado' => MovimientoBancario::SIN_IDENTIFICAR,
                ]));
            }

            $this->conciliador->clasificar($nuevos, $cuentaId, $mes);
            $estado->forceFill(['duplicados' => $duplicados])->save();

            return ['estado' => $estado, 'nuevos' => $nuevos->count(), 'duplicados' => $duplicados, 'fuera_del_mes' => $fuera];
        });
    }
}
