<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Reservas\Models\ReglaArea;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Reservas\Services\FranjasDeArea;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: un residente reserva un área común para una de sus unidades. Se exige que la unidad sea suya, que
 * acepte el reglamento y que se cumplan las reglas del área (día, horario, duración, anticipación, tope mensual).
 * Las reservas que no se cruzan las garantiza la base; si dos personas piden la misma franja, gana la primera.
 * Con aprobación del administrador queda `pendiente`; sin ella, `confirmada`.
 */
final class ReservarAreaAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly FranjasDeArea $franjas,
        private readonly UnidadesDeCuentaAction $unidades,
    ) {}

    public function execute(User $user, int $amenidadId, int $unidadId, string $fecha, string $inicio, int $horas, bool $aceptaReglamento): Reserva
    {
        if (! $aceptaReglamento) {
            throw new ApiException('REGLAMENTO_NO_ACEPTADO', 'Debes aceptar el reglamento del área para reservar.', 422);
        }
        if (! in_array($unidadId, array_column($this->unidades->execute($user->id), 'unidad_id'), true)) {
            throw new ApiException('UNIDAD_NO_ES_SUYA', 'Solo puedes reservar para una unidad en la que vives.', 403);
        }

        $area = CondominioAmenidad::query()->where('reservable', true)->findOrFail($amenidadId);
        $regla = ReglaArea::query()->where('amenidad_id', $area->id)->first()
            ?? throw new ApiException('AREA_SIN_REGLAS', 'Esta área aún no está habilitada para reservas.', 409);

        $this->exigirDisponible($area, $fecha);
        $this->exigirReglas($regla, $fecha, $inicio, $horas);

        try {
            return DB::transaction(function () use ($user, $area, $regla, $unidadId, $fecha, $inicio, $horas): Reserva {
                $this->exigirTopeMensual($regla, $area->id, $unidadId, $fecha);

                // Savepoint: un choque de horario aborta solo esta parte de la transacción
                return DB::transaction(fn () => Reserva::create([
                    'amenidad_id' => $area->id, 'unidad_id' => $unidadId, 'solicitante_id' => $user->id, 'fecha' => $fecha,
                    'inicio' => $inicio, 'fin' => $this->franjas->fin($inicio, $horas),
                    'estado' => $area->requiere_aprobacion ? Reserva::PENDIENTE : Reserva::CONFIRMADA,
                    'costo' => (string) $regla->costo, 'garantia' => (string) $regla->garantia, 'reglamento_aceptado_en' => now(),
                ]));
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23P01') {
                throw new ApiException('HORARIO_OCUPADO', 'Ese horario acaba de ser reservado. Elige otro.', 409);
            }

            throw $e;
        }
    }

    private function exigirDisponible(CondominioAmenidad $area, string $fecha): void
    {
        if (! $area->activa) {
            throw new ApiException('AREA_NO_DISPONIBLE', 'Esta área no está disponible.', 409);
        }
        if ($area->mantenimiento_hasta !== null && $area->mantenimiento_hasta->toDateString() >= $fecha) {
            throw new ApiException('AREA_EN_MANTENIMIENTO', 'El área estará en mantenimiento hasta el '.$area->mantenimiento_hasta->format('d/m/Y').'.', 409);
        }
    }

    private function exigirReglas(ReglaArea $r, string $fecha, string $inicio, int $horas): void
    {
        if (! in_array($this->franjas->diaDeSemana($fecha), $r->dias_semana, true)) {
            throw new ApiException('DIA_NO_HABILITADO', 'El área no se reserva ese día de la semana.', 422);
        }
        if (! $this->franjas->valida($r, $inicio, $horas)) {
            throw new ApiException('HORARIO_NO_VALIDO', "El horario no cumple las reglas del área ({$r->duracion_min_horas} a {$r->duracion_max_horas} horas, entre ".substr($r->abre, 0, 5).' y '.substr($r->cierra, 0, 5).').', 422);
        }

        $zona = $this->calendario->zonaHoraria();
        $ahora = CarbonImmutable::now($zona);
        $empieza = CarbonImmutable::parse("{$fecha} {$inicio}", $zona);
        if ($empieza < $ahora->addHours($r->anticipacion_min_horas)) {
            throw new ApiException('ANTICIPACION_INSUFICIENTE', "Debes reservar con al menos {$r->anticipacion_min_horas} horas de anticipación.", 422);
        }
        if ($fecha > $ahora->addDays($r->anticipacion_max_dias)->toDateString()) {
            throw new ApiException('DEMASIADO_ADELANTE', "Solo se puede reservar hasta con {$r->anticipacion_max_dias} días de anticipación.", 422);
        }
    }

    private function exigirTopeMensual(ReglaArea $r, int $amenidadId, int $unidadId, string $fecha): void
    {
        if ($r->reservas_por_unidad_mes === null) {
            return;
        }
        // Las reservas de esta área se serializan por su fila de reglas: dos pedidos a la vez no superan el tope
        ReglaArea::query()->where('amenidad_id', $amenidadId)->lockForUpdate()->first();
        $mes = CarbonImmutable::parse($fecha);
        $usadas = Reserva::query()->where('amenidad_id', $amenidadId)->where('unidad_id', $unidadId)->whereIn('estado', Reserva::VIVAS)
            ->whereBetween('fecha', [$mes->startOfMonth()->toDateString(), $mes->endOfMonth()->toDateString()])->count();

        if ($usadas >= $r->reservas_por_unidad_mes) {
            throw new ApiException('TOPE_MENSUAL', "Tu unidad ya usó sus {$r->reservas_por_unidad_mes} reserva(s) de este mes en esta área.", 409);
        }
    }
}
