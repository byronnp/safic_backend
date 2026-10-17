<?php

namespace App\Modules\Garita\Actions;

use App\Core\Storage\ArchivosCondominio;
use App\Core\Tenancy\Calendario;
use App\Modules\Garita\Models\Paquete;
use App\Modules\Unidades\Actions\OcupantesVigentesAction;
use App\Modules\Unidades\Models\Unidad;
use Carbon\CarbonImmutable;

/**
 * Consulta del guardia: los paquetes que están en la garita (los que llevan más de 7 días, marcados) y los entregados hoy, con
 * quiénes pueden retirar cada uno (los ocupantes vigentes de la unidad).
 */
final class ListarPaquetesGaritaAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly ArchivosCondominio $archivos,
        private readonly OcupantesVigentesAction $ocupantes,
    ) {}

    /** @return array{en_garita: list<array<string, mixed>>, entregados_hoy: list<array<string, mixed>>} */
    public function execute(): array
    {
        $zona = $this->calendario->zonaHoraria();
        $ahora = CarbonImmutable::now($zona);
        $unidades = Unidad::query()->withTrashed()->get(['id', 'codigo'])->pluck('codigo', 'id');

        $enGarita = Paquete::query()->where('estado', Paquete::EN_GARITA)->orderBy('recibido_en')->get();
        $entregados = Paquete::query()->where('estado', Paquete::ENTREGADO)->where('entregado_en', '>=', $ahora->startOfDay())->orderByDesc('entregado_en')->get();

        $porUnidad = [];
        foreach ($enGarita->pluck('unidad_id')->unique() as $id) {
            $porUnidad[$id] = array_map(fn (array $o): array => ['id' => $o['id'], 'nombre' => $o['nombre'], 'relacion' => $o['relacion']], $this->ocupantes->execute((int) $id));
        }

        return [
            'en_garita' => $enGarita->map(function (Paquete $p) use ($unidades, $ahora, $zona, $porUnidad): array {
                $dias = (int) $p->recibido_en->setTimezone($zona)->startOfDay()->diffInDays($ahora->startOfDay());

                return [
                    'id' => $p->id, 'unidad' => $unidades[$p->unidad_id] ?? '', 'empresa' => $p->empresa, 'descripcion' => $p->descripcion,
                    'recibido_en' => $p->recibido_en->setTimezone($zona)->toIso8601String(), 'dias' => $dias, 'atrasado' => $dias >= Paquete::DIAS_ATRASADO,
                    'foto_url' => $this->archivos->existe($p->foto_ruta) ? $this->archivos->urlTemporal($p->foto_ruta) : null,
                    'ocupantes' => $porUnidad[$p->unidad_id] ?? [],
                ];
            })->values()->all(),
            'entregados_hoy' => $entregados->map(fn (Paquete $p): array => [
                'id' => $p->id, 'unidad' => $unidades[$p->unidad_id] ?? '', 'empresa' => $p->empresa, 'descripcion' => $p->descripcion,
                'entregado_en' => $p->entregado_en?->setTimezone($zona)->toIso8601String(), 'retira_nombre' => $p->retira_nombre,
            ])->values()->all(),
        ];
    }
}
