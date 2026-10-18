<?php

namespace App\Modules\Garita\Actions;

use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Unidades\Models\Unidad;
use Carbon\CarbonImmutable;

/**
 * Consulta del guardia: la copia de los QR que sirven hoy y mañana, para validar en el teléfono cuando no hay red. No incluye la cédula
 * de nadie. Cada QR trae lo mínimo para validarlo y mostrar quién es.
 */
final class QrVigentesAction
{
    public function __construct(private readonly Calendario $calendario) {}

    /** @return array{generado_en: string, zona: string, qrs: list<array<string, mixed>>} */
    public function execute(): array
    {
        $zona = $this->calendario->zonaHoraria();
        $hoy = CarbonImmutable::now($zona);
        $manana = $hoy->addDay()->toDateString();

        $autorizaciones = AutorizacionVisita::query()->whereNull('revocada_en')
            ->whereDate('fecha_desde', '<=', $manana)->whereDate('fecha_hasta', '>=', $hoy->toDateString())->orderBy('id')->get()
            ->filter(fn (AutorizacionVisita $a): bool => $a->tipo === AutorizacionVisita::RECURRENTE || $a->usos === 0);

        $unidades = Unidad::query()->withTrashed()->whereIn('id', $autorizaciones->pluck('unidad_id'))->pluck('codigo', 'id');
        $quien = User::query()->whereIn('id', $autorizaciones->pluck('creada_por')->filter())->pluck('name', 'id');
        $dentro = AccesoGarita::query()->where('estado', AccesoGarita::DENTRO)->whereNotNull('autorizacion_id')->pluck('autorizacion_id')->all();

        return [
            'generado_en' => $hoy->toIso8601String(),
            'zona' => $zona,
            'qrs' => $autorizaciones->map(fn (AutorizacionVisita $a): array => [
                'token' => $a->token, 'visitante' => $a->visitante_nombre, 'placa' => $a->placa, 'unidad' => $unidades[$a->unidad_id] ?? null,
                'autorizo' => $quien[$a->creada_por] ?? null, 'tipo' => $a->tipo,
                'fecha_desde' => $a->fecha_desde->toDateString(), 'fecha_hasta' => $a->fecha_hasta->toDateString(),
                'hora_desde' => substr($a->hora_desde, 0, 5), 'hora_hasta' => substr($a->hora_hasta, 0, 5), 'dias_semana' => $a->dias_semana,
                'ya_dentro' => in_array($a->id, $dentro, true),
            ])->values()->all(),
        ];
    }
}
