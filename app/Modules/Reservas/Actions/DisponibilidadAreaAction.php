<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Reservas\Models\ReglaArea;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Reservas\Services\FranjasDeArea;
use Carbon\CarbonImmutable;

/**
 * Consulta para el residente: por cada día pedido, las franjas del área y si están libres, ocupadas o cerradas
 * (día no habilitado, fuera de la ventana de anticipación, o área en mantenimiento).
 */
final class DisponibilidadAreaAction
{
    public const MAX_DIAS = 14;

    public function __construct(private readonly Calendario $calendario, private readonly FranjasDeArea $franjas) {}

    /** @return list<array{fecha: string, franjas: list<array{inicio: string, fin: string, estado: string}>}> */
    public function execute(int $amenidadId, string $desde, int $dias): array
    {
        $area = CondominioAmenidad::query()->where('reservable', true)->where('activa', true)->findOrFail($amenidadId);
        $regla = ReglaArea::query()->where('amenidad_id', $area->id)->first()
            ?? throw new ApiException('AREA_SIN_REGLAS', 'Esta área aún no está habilitada para reservas.', 409);

        $dias = max(1, min($dias, self::MAX_DIAS));
        $zona = $this->calendario->zonaHoraria();
        $ahora = CarbonImmutable::now($zona);
        $limite = $ahora->addDays($regla->anticipacion_max_dias)->toDateString();
        $primero = CarbonImmutable::parse($desde, $zona);

        $ultimo = $primero->addDays($dias - 1)->toDateString();
        $ocupadas = Reserva::query()->where('amenidad_id', $area->id)->whereIn('estado', Reserva::VIVAS)
            ->whereBetween('fecha', [$primero->toDateString(), $ultimo])->get(['fecha', 'inicio', 'fin']);

        $resultado = [];
        for ($i = 0; $i < $dias; $i++) {
            $fecha = $primero->addDays($i)->toDateString();
            $habilitado = in_array($this->franjas->diaDeSemana($fecha), $regla->dias_semana, true)
                && $fecha <= $limite
                && ($area->mantenimiento_hasta === null || $area->mantenimiento_hasta->toDateString() < $fecha);

            $resultado[] = ['fecha' => $fecha, 'franjas' => array_map(function (array $f) use ($habilitado, $fecha, $ahora, $regla, $ocupadas, $zona): array {
                $empieza = CarbonImmutable::parse("{$fecha} {$f['inicio']}", $zona);
                $cerrada = ! $habilitado || $empieza < $ahora->addHours($regla->anticipacion_min_horas);
                $ocupada = $ocupadas->contains(fn (Reserva $r) => $r->fecha->toDateString() === $fecha && substr($r->inicio, 0, 5) < $f['fin'] && substr($r->fin, 0, 5) > $f['inicio']);

                return $f + ['estado' => $cerrada ? 'cerrada' : ($ocupada ? 'ocupada' : 'libre')];
            }, $this->franjas->delDia($regla))];
        }

        return $resultado;
    }
}
