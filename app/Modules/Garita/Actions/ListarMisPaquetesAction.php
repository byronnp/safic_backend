<?php

namespace App\Modules\Garita\Actions;

use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Garita\Models\Paquete;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Carbon\CarbonImmutable;

/**
 * Consulta para el residente: los paquetes de sus unidades que esperan en la garita y los entregados en los últimos 30 días.
 */
final class ListarMisPaquetesAction
{
    public function __construct(private readonly Calendario $calendario, private readonly UnidadesDeCuentaAction $unidades) {}

    /** @return array{en_garita: list<array<string, mixed>>, entregados: list<array<string, mixed>>} */
    public function execute(User $user): array
    {
        $mias = collect($this->unidades->execute($user->id))->keyBy('unidad_id');
        $zona = $this->calendario->zonaHoraria();
        $ahora = CarbonImmutable::now($zona);

        $paquetes = Paquete::query()->whereIn('unidad_id', $mias->keys())
            ->where(fn ($q) => $q->where('estado', Paquete::EN_GARITA)->orWhere('entregado_en', '>=', $ahora->subDays(30)))
            ->orderByDesc('recibido_en')->get();

        $item = fn (Paquete $p): array => [
            'id' => $p->id, 'unidad' => $mias->get($p->unidad_id)['codigo'] ?? '', 'empresa' => $p->empresa, 'descripcion' => $p->descripcion,
            'recibido_en' => $p->recibido_en->setTimezone($zona)->toIso8601String(),
            'entregado_en' => $p->entregado_en?->setTimezone($zona)->toIso8601String(),
            'dias' => (int) $p->recibido_en->setTimezone($zona)->startOfDay()->diffInDays($ahora->startOfDay()),
        ];

        return [
            'en_garita' => $paquetes->where('estado', Paquete::EN_GARITA)->map($item)->values()->all(),
            'entregados' => $paquetes->where('estado', Paquete::ENTREGADO)->map($item)->values()->all(),
        ];
    }
}
