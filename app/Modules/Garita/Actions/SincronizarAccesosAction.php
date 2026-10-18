<?php

namespace App\Modules\Garita\Actions;

use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Garita\Services\ValidadorDeVisita;
use App\Modules\Unidades\Models\Unidad;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del guardia: enviar al servidor lo que registró sin conexión. Es idempotente (cada ingreso trae su `uuid`) y no pierde nada:
 * un ingreso que ya ocurrió se guarda aunque su QR ya no sirviera; en ese caso queda con una nota y se le avisa al guardia (`conflicto`).
 *
 * Resultados por ingreso: `ok`, `duplicado` (ya estaba), `conflicto` (se guardó con una nota) o `rechazado` (no se guardó: dato imposible).
 */
final class SincronizarAccesosAction
{
    public const MAXIMO = 200;

    public function __construct(private readonly Calendario $calendario, private readonly ValidadorDeVisita $validador) {}

    /**
     * @param  list<array<string, mixed>>  $ingresos
     * @param  list<array<string, mixed>>  $salidas
     * @return array{ingresos: list<array<string, mixed>>, salidas: list<array<string, mixed>>}
     */
    public function execute(User $guardia, array $ingresos, array $salidas): array
    {
        $r = ['ingresos' => [], 'salidas' => []];
        foreach ($ingresos as $i) {
            $r['ingresos'][] = ['uuid' => $i['uuid']] + DB::transaction(fn (): array => $this->ingreso($guardia, $i));
        }
        foreach ($salidas as $s) {
            $r['salidas'][] = ['acceso_id' => $s['acceso_id']] + DB::transaction(fn (): array => $this->salida($guardia, $s));
        }

        return $r;
    }

    /** @param array<string, mixed> $i @return array<string, mixed> */
    private function ingreso(User $guardia, array $i): array
    {
        $existente = AccesoGarita::query()->where('uuid', $i['uuid'])->lockForUpdate()->first();
        $entrada = CarbonImmutable::parse((string) $i['entrada_en']);
        $salida = isset($i['salida_en']) ? CarbonImmutable::parse((string) $i['salida_en']) : null;

        if ($existente !== null) {
            // Pudo haberse enviado sin la salida y ahora llegar con ella
            if ($salida !== null && $existente->estado === AccesoGarita::DENTRO) {
                $existente->forceFill(['estado' => AccesoGarita::SALIO, 'salida_en' => max($salida, $entrada), 'salida_por' => $guardia->id])->save();
            }

            return ['resultado' => 'duplicado', 'acceso_id' => $existente->id];
        }
        if ($entrada->isAfter(now()->addMinutes(10)) || $entrada->isBefore(now()->subDays(7)) || ($salida !== null && $salida->isBefore($entrada))) {
            return ['resultado' => 'rechazado', 'motivo' => 'FECHA_INVALIDA'];
        }

        $base = [
            'uuid' => $i['uuid'], 'sin_conexion' => true, 'sincronizado_en' => now(), 'entrada_en' => $entrada, 'registrado_por' => $guardia->id,
            'estado' => $salida === null ? AccesoGarita::DENTRO : AccesoGarita::SALIO, 'salida_en' => $salida, 'salida_por' => $salida === null ? null : $guardia->id,
        ];

        $token = isset($i['token']) ? (string) $i['token'] : null;
        $autorizacion = $token === null ? null : AutorizacionVisita::query()->where('token', $token)->lockForUpdate()->first();

        if ($token !== null) {
            // Un token de otro condominio o inventado: no hay a qué asociarlo, se guarda como visita sin QR con una nota
            if ($autorizacion === null) {
                $a = AccesoGarita::create($base + $this->libre($i, 'qr') + ['nota' => 'QR no reconocido al sincronizar']);

                return ['resultado' => 'conflicto', 'motivo' => 'QR_NO_RECONOCIDO', 'acceso_id' => $a->id];
            }

            $motivo = $this->validador->motivo($autorizacion, $entrada->setTimezone($this->calendario->zonaHoraria()));
            $yaDentro = $motivo === null && AccesoGarita::query()->where('autorizacion_id', $autorizacion->id)->where('estado', AccesoGarita::DENTRO)->exists();
            $problema = $yaDentro ? 'QR_YA_DENTRO' : $motivo;

            $a = AccesoGarita::create($base + [
                'autorizacion_id' => $autorizacion->id, 'unidad_id' => $autorizacion->unidad_id, 'tipo' => 'visita', 'nombre' => $autorizacion->visitante_nombre,
                'documento' => $autorizacion->visitante_documento, 'placa' => $autorizacion->placa, 'autorizado_por' => 'qr',
                'nota' => $problema === null ? null : 'Conflicto al sincronizar: '.($problema === 'QR_YA_DENTRO' ? 'esa persona ya figuraba dentro' : $this->validador->mensaje($problema)),
            ]);
            if ($problema === null) {
                $autorizacion->forceFill(['usos' => $autorizacion->usos + 1])->save();
            }

            return $problema === null ? ['resultado' => 'ok', 'acceso_id' => $a->id] : ['resultado' => 'conflicto', 'motivo' => $problema, 'acceso_id' => $a->id];
        }

        $a = AccesoGarita::create($base + $this->libre($i, (string) $i['autorizado_por']));

        return ['resultado' => 'ok', 'acceso_id' => $a->id];
    }

    /** Campos de un ingreso sin QR (visita sin anunciar, proveedor, delivery) o de un QR que no se pudo asociar. @param array<string, mixed> $i @return array<string, mixed> */
    private function libre(array $i, string $autorizadoPor): array
    {
        $unidad = isset($i['unidad_codigo']) && $i['unidad_codigo'] !== '' ? Unidad::query()->whereRaw('lower(codigo) = ?', [mb_strtolower((string) $i['unidad_codigo'])])->value('id') : null;

        return [
            'tipo' => $i['tipo'], 'nombre' => $i['nombre'], 'documento' => $i['documento'] ?? null, 'unidad_id' => $unidad,
            'placa' => isset($i['placa']) && $i['placa'] !== '' ? strtoupper((string) $i['placa']) : null,
            'destino' => isset($i['destino']) && $i['destino'] !== '' ? $i['destino'] : null, 'autorizado_por' => $autorizadoPor,
        ];
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function salida(User $guardia, array $s): array
    {
        $a = AccesoGarita::query()->lockForUpdate()->find($s['acceso_id']);
        if ($a === null) {
            return ['resultado' => 'rechazado', 'motivo' => 'ACCESO_NO_ENCONTRADO'];
        }
        if ($a->estado !== AccesoGarita::DENTRO) {
            return ['resultado' => 'duplicado'];
        }
        $salida = CarbonImmutable::parse((string) $s['salida_en']);
        $a->forceFill(['estado' => AccesoGarita::SALIO, 'salida_en' => $salida->isBefore($a->entrada_en) ? $a->entrada_en : $salida, 'salida_por' => $guardia->id])->save();

        return ['resultado' => 'ok'];
    }
}
