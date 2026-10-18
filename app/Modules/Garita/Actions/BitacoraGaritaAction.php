<?php

namespace App\Modules\Garita\Actions;

use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Unidades\Models\Unidad;
use Carbon\CarbonImmutable;

/**
 * Consulta de la administración: la bitácora de garita (ingresos y salidas) de un rango de fechas, con los indicadores del
 * día. Los más recientes primero; tope de 200 filas por consulta.
 */
final class BitacoraGaritaAction
{
    public const MAXIMO = 200;

    public function __construct(private readonly Calendario $calendario) {}

    /** @return array<string, mixed> */
    public function execute(?string $desde, ?string $hasta, ?string $tipo, ?string $buscar): array
    {
        $zona = $this->calendario->zonaHoraria();
        $hoy = $this->calendario->hoy();
        $desde ??= $hoy;
        $hasta ??= $hoy;

        // Los límites del día son locales, pero la base guarda los instantes en UTC: se convierten para no correr el día 5 horas
        $utc = (string) config('app.timezone');
        $inicio = CarbonImmutable::parse($desde, $zona)->startOfDay()->setTimezone($utc);
        $fin = CarbonImmutable::parse($hasta, $zona)->endOfDay()->setTimezone($utc);
        $unidades = Unidad::query()->get(['id', 'codigo'])->pluck('codigo', 'id');

        $consulta = AccesoGarita::query()->whereBetween('entrada_en', [$inicio, $fin])->orderByDesc('entrada_en')->orderByDesc('id');
        if ($tipo !== null) {
            $consulta->where('tipo', $tipo);
        }
        $filas = $consulta->limit(self::MAXIMO + 1)->get();
        if ($buscar !== null && trim($buscar) !== '') {
            $q = mb_strtolower(trim($buscar));
            $filas = $filas->filter(fn (AccesoGarita $a): bool => str_contains(mb_strtolower($a->nombre), $q) || str_contains(mb_strtolower((string) ($unidades[$a->unidad_id] ?? $a->destino ?? '')), $q))->values();
        }
        $hayMas = $filas->count() > self::MAXIMO;

        $delDia = AccesoGarita::query()->whereBetween('entrada_en', [CarbonImmutable::parse($hoy, $zona)->startOfDay()->setTimezone($utc), CarbonImmutable::parse($hoy, $zona)->endOfDay()->setTimezone($utc)])->get();
        $dentro = AccesoGarita::query()->where('estado', AccesoGarita::DENTRO)->get();
        $usuarios = User::query()->whereIn('id', $filas->pluck('registrado_por')->filter())->get()->keyBy('id');

        return [
            'desde' => $desde, 'hasta' => $hasta, 'hay_mas' => $hayMas,
            'indicadores' => [
                'dentro_ahora' => $dentro->count(),
                'proveedores_dentro' => $dentro->where('tipo', 'proveedor')->count(),
                'ingresos_hoy' => $delDia->count(),
                'con_qr_hoy' => $delDia->where('autorizado_por', 'qr')->count(),
                'sin_anunciar_hoy' => $delDia->where('tipo', 'visita')->where('autorizado_por', '!=', 'qr')->count(),
                'sin_conexion' => 0,
            ],
            'accesos' => $filas->take(self::MAXIMO)->map(fn (AccesoGarita $a): array => [
                'id' => $a->id, 'nombre' => $a->nombre, 'tipo' => $a->tipo,
                'unidad' => $unidades[$a->unidad_id] ?? null, 'destino' => $a->destino, 'autorizado_por' => $a->autorizado_por, 'estado' => $a->estado,
                'entrada_en' => $a->entrada_en->setTimezone($zona)->toIso8601String(),
                'salida_en' => $a->salida_en?->setTimezone($zona)->toIso8601String(),
                'registrado_por' => $usuarios->get($a->registrado_por)->name ?? null,
            ])->values()->all(),
        ];
    }
}
