<?php

namespace App\Modules\Garita\Actions;

use App\Core\Tenancy\Calendario;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Garita\Services\ValidadorDeVisita;
use App\Modules\Unidades\Models\Unidad;
use Carbon\CarbonImmutable;

/**
 * Consulta del guardia: quién está dentro ahora y qué visitas autorizadas se esperan hoy (con QR vigente hoy y que aún no entraron).
 */
final class PanelGaritaAction
{
    public function __construct(private readonly Calendario $calendario, private readonly ValidadorDeVisita $validador) {}

    /** @return array{dentro: list<array<string, mixed>>, esperadas: list<array<string, mixed>>} */
    public function execute(): array
    {
        $dentro = AccesoGarita::query()->where('estado', AccesoGarita::DENTRO)->orderBy('entrada_en')->get();
        $unidades = Unidad::query()->get(['id', 'codigo'])->pluck('codigo', 'id');

        $zona = $this->calendario->zonaHoraria();
        $ahora = CarbonImmutable::now($zona);
        // Para «esperadas» importa el día, no la hora exacta: se evalúa al mediodía del día de hoy
        $referencia = $ahora->setTime(12, 0);
        $dentroIds = $dentro->pluck('autorizacion_id')->filter()->all();

        $esperadas = AutorizacionVisita::query()->whereNull('revocada_en')->whereDate('fecha_hasta', '>=', $ahora->toDateString())->whereDate('fecha_desde', '<=', $ahora->toDateString())
            ->orderBy('hora_desde')->get()
            ->filter(fn (AutorizacionVisita $a): bool => ! in_array($a->id, $dentroIds, true)
                && ($a->tipo === AutorizacionVisita::RECURRENTE || $a->usos === 0)
                && ! in_array($this->validador->motivo($a, $referencia->setTime((int) substr($a->hora_desde, 0, 2), (int) substr($a->hora_desde, 3, 2))), [ValidadorDeVisita::REVOCADA, ValidadorDeVisita::YA_USADA, ValidadorDeVisita::VENCIDA, ValidadorDeVisita::AUN_NO, ValidadorDeVisita::DIA], true)
                && substr($a->hora_hasta, 0, 5) >= $ahora->format('H:i'));

        return [
            'dentro' => $dentro->map(fn (AccesoGarita $a): array => [
                'id' => $a->id, 'nombre' => $a->nombre, 'tipo' => $a->tipo, 'unidad' => $unidades[$a->unidad_id] ?? null, 'destino' => $a->destino,
                'placa' => $a->placa, 'desde' => $a->entrada_en->setTimezone($zona)->format('H:i'),
            ])->values()->all(),
            'esperadas' => $esperadas->map(fn (AutorizacionVisita $a): array => [
                'id' => $a->id, 'nombre' => $a->visitante_nombre, 'unidad' => $unidades[$a->unidad_id] ?? null, 'placa' => $a->placa,
                'hora_desde' => substr($a->hora_desde, 0, 5), 'hora_hasta' => substr($a->hora_hasta, 0, 5),
            ])->values()->all(),
        ];
    }
}
