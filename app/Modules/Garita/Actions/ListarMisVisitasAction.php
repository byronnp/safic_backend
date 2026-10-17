<?php

namespace App\Modules\Garita\Actions;

use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;

/**
 * Consulta para el residente: las autorizaciones activas de sus unidades (las más próximas primero) y las unidades
 * desde las que puede autorizar. El token del QR solo se entrega a quien creó la autorización o vive en la unidad.
 */
final class ListarMisVisitasAction
{
    public function __construct(private readonly Calendario $calendario, private readonly UnidadesDeCuentaAction $unidades) {}

    /** @return array{unidades: list<array{unidad_id: int, codigo: string}>, visitas: list<array<string, mixed>>} */
    public function execute(User $user): array
    {
        $mias = collect($this->unidades->execute($user->id))->keyBy('unidad_id');
        $hoy = $this->calendario->hoy();

        $visitas = AutorizacionVisita::query()->whereIn('unidad_id', $mias->keys())->whereNull('revocada_en')
            ->whereDate('fecha_hasta', '>=', $hoy)->orderBy('fecha_desde')->orderBy('hora_desde')->orderBy('id')->get()
            ->filter(fn (AutorizacionVisita $a): bool => $a->estadoEn($hoy) === 'activa')
            ->map(fn (AutorizacionVisita $a): array => self::item($a, $mias->get($a->unidad_id)['codigo'] ?? ''))->values()->all();

        return [
            'unidades' => $mias->map(fn (array $u): array => ['unidad_id' => $u['unidad_id'], 'codigo' => $u['codigo']])->values()->all(),
            'visitas' => $visitas,
        ];
    }

    /** @return array<string, mixed> */
    public static function item(AutorizacionVisita $a, string $unidad): array
    {
        return [
            'id' => $a->id, 'unidad' => $unidad, 'visitante' => $a->visitante_nombre, 'placa' => $a->placa, 'tipo' => $a->tipo,
            'fecha_desde' => $a->fecha_desde->toDateString(), 'fecha_hasta' => $a->fecha_hasta->toDateString(),
            'hora_desde' => substr($a->hora_desde, 0, 5), 'hora_hasta' => substr($a->hora_hasta, 0, 5),
            'dias_semana' => $a->dias_semana, 'token' => $a->token,
        ];
    }
}
