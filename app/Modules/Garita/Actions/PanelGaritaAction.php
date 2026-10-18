<?php

namespace App\Modules\Garita\Actions;

use App\Core\Tenancy\Calendario;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Garita\Models\SolicitudIngreso;
use App\Modules\Garita\Services\ValidadorDeVisita;
use App\Modules\Unidades\Models\Unidad;
use Carbon\CarbonImmutable;

/**
 * Consulta del guardia: quién está dentro ahora y qué visitas autorizadas se esperan hoy (con QR vigente hoy y que aún no entraron).
 */
final class PanelGaritaAction
{
    public function __construct(private readonly Calendario $calendario, private readonly ValidadorDeVisita $validador) {}

    /** @return array{dentro: list<array<string, mixed>>, esperadas: list<array<string, mixed>>, solicitudes: list<array<string, mixed>>} */
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

        // Las preguntas a residentes de la última media hora que siguen abiertas para el guardia (no usadas ni retiradas)
        $solicitudes = SolicitudIngreso::query()->whereIn('estado', [SolicitudIngreso::PENDIENTE, SolicitudIngreso::PERMITIDA, SolicitudIngreso::RECHAZADA])
            ->where('created_at', '>=', now()->subMinutes(30))->orderBy('created_at')->get();

        return [
            'solicitudes' => $solicitudes->map(fn (SolicitudIngreso $s): array => [
                'id' => $s->id, 'nombre' => $s->visitante_nombre, 'tipo' => $s->tipo, 'unidad' => $unidades[$s->unidad_id] ?? null, 'placa' => $s->placa,
                'estado' => $s->estadoEfectivo(), 'segundos_restantes' => max(0, (int) now()->diffInSeconds($s->expira_en, false)),
                'puede_ingresar' => $s->estado === SolicitudIngreso::PERMITIDA && $s->respondida_en !== null && $s->respondida_en->addMinutes(RegistrarIngresoDeSolicitudAction::MINUTOS_DE_VIGENCIA)->isFuture(),
            ])->values()->all(),
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
