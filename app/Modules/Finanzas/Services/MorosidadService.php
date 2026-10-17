<?php

namespace App\Modules\Finanzas\Services;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Models\ConfiguracionFinanzas;
use App\Modules\Finanzas\Models\ConvenioPago;
use App\Modules\Finanzas\Models\RestriccionMora;
use App\Modules\Unidades\Actions\UnidadesDePersonaAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Quién está en mora y qué se hace con eso (Decreto 462).
 * - Una unidad **debe** cuando tiene cuotas vencidas con saldo.
 * - Está **en mora** cuando su cuota vencida más antigua pasó los días de gracia y no tiene un
 *   convenio de pago al día.
 * - Se **restringe** el uso de áreas comunes cuando debe cuotas de al menos N meses distintos y
 *   no tiene un convenio al día: primero se notifica y la restricción aplica pasados unos días.
 * Visitas, paquetes y seguridad nunca se restringen.
 */
final class MorosidadService
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly UnidadesDePersonaAction $unidadesDePersona,
    ) {}

    /** @return array{dias_gracia: int, meses_restriccion: int, dias_aviso_restriccion: int} */
    public function reglas(): array
    {
        $c = ConfiguracionFinanzas::vigente();

        return [
            'dias_gracia' => (int) $c->dias_gracia,
            'meses_restriccion' => (int) $c->meses_restriccion,
            'dias_aviso_restriccion' => (int) $c->dias_aviso_restriccion,
        ];
    }

    /**
     * La deuda vencida de cada unidad que la tiene.
     *
     * @param  list<int>|null  $unidadIds
     * @return array<int, array{unidad_id: int, cuotas: int, meses: int, dias: int, saldo: int, primera: string}>
     */
    public function deudas(?array $unidadIds = null): array
    {
        $hoy = $this->calendario->hoy();
        $filas = DB::table('cuotas')
            ->whereColumn('monto', '>', 'pagado')->where('vence_el', '<', $hoy)
            ->when($unidadIds !== null, fn ($q) => $q->whereIn('unidad_id', $unidadIds))
            ->groupBy('unidad_id')
            ->selectRaw('unidad_id, count(*) as cuotas, count(distinct periodo) as meses, min(vence_el) as primera, sum(monto - pagado) as saldo')
            ->get();

        $deudas = [];
        foreach ($filas as $f) {
            $deudas[(int) $f->unidad_id] = [
                'unidad_id' => (int) $f->unidad_id,
                'cuotas' => (int) $f->cuotas,
                'meses' => (int) $f->meses,
                'dias' => (int) CarbonImmutable::parse((string) $f->primera)->diffInDays(CarbonImmutable::parse($hoy)),
                'saldo' => Dinero::centavos((string) $f->saldo),
                'primera' => (string) $f->primera,
            ];
        }

        return $deudas;
    }

    /**
     * Convenios activos por unidad, con su avance.
     *
     * @param  list<int>|null  $unidadIds
     * @return array<int, array{convenio: ConvenioPago, pagado: int, cubiertas: int, al_dia: bool, proxima: string|null}>
     */
    public function convenios(?array $unidadIds = null): array
    {
        $resultado = [];
        $activos = ConvenioPago::query()->where('estado', ConvenioPago::ACTIVO)
            ->when($unidadIds !== null, fn ($q) => $q->whereIn('unidad_id', $unidadIds))->get();

        foreach ($activos as $c) {
            $resultado[$c->unidad_id] = $this->avance($c);
        }

        return $resultado;
    }

    /**
     * Cuotas del convenio, de la primera a la última: las iguales y la última con lo que sobra.
     *
     * @return list<array{numero: int, vence_el: string, monto: int}>
     */
    public function plan(ConvenioPago $c): array
    {
        $montos = Dinero::repartir(Dinero::centavos((string) $c->monto_total), array_fill(0, $c->cuotas, 1));
        $primera = CarbonImmutable::parse($c->primera_cuota_el);

        return array_map(fn (int $i): array => [
            'numero' => $i + 1,
            'vence_el' => $primera->addMonthsNoOverflow($i)->toDateString(),
            'monto' => $montos[$i],
        ], array_keys($montos));
    }

    /**
     * @return array{convenio: ConvenioPago, pagado: int, cubiertas: int, al_dia: bool, proxima: string|null}
     */
    public function avance(ConvenioPago $c): array
    {
        $hoy = $this->calendario->hoy();
        $pagado = Dinero::centavos((string) DB::table('pagos')->where('unidad_id', $c->unidad_id)->where('estado', 'aprobado')
            ->where('fecha_pago', '>=', $c->desde->toDateString())->sum('monto'));

        $acumulado = 0;
        $cubiertas = 0;
        $exigido = 0;
        $proxima = null;
        foreach ($this->plan($c) as $cuota) {
            $acumulado += $cuota['monto'];
            if ($pagado >= $acumulado) {
                $cubiertas++;
            } elseif ($proxima === null) {
                $proxima = $cuota['vence_el'];
            }
            if ($cuota['vence_el'] <= $hoy) {
                $exigido = $acumulado;
            }
        }

        return ['convenio' => $c, 'pagado' => $pagado, 'cubiertas' => $cubiertas, 'al_dia' => $pagado >= $exigido, 'proxima' => $proxima];
    }

    /** ¿Está en mora? (pasó los días de gracia y no tiene un convenio al día) */
    public function enMora(array $deuda, ?array $convenio): bool
    {
        return $deuda['dias'] > $this->reglas()['dias_gracia'] && ! ($convenio !== null && $convenio['al_dia']);
    }

    /** ¿Debe restringirse? (deuda de N meses o más y sin convenio al día) */
    public function debeRestringirse(array $deuda, ?array $convenio): bool
    {
        return $deuda['meses'] >= $this->reglas()['meses_restriccion'] && ! ($convenio !== null && $convenio['al_dia']);
    }

    /** ¿Tiene hoy la restricción de áreas comunes en vigor? Lo consulta quien reserva o da acceso a áreas. */
    public function restringida(int $unidadId): bool
    {
        return RestriccionMora::query()->where('unidad_id', $unidadId)->where('estado', RestriccionMora::ACTIVA)->exists();
    }

    /** ¿La persona, propietaria de alguna unidad, está en mora? (no puede ocupar un cargo de la directiva) */
    public function personaEnMora(int $personaId): bool
    {
        $unidades = $this->unidadesDePersona->propietaria($personaId);
        if ($unidades === []) {
            return false;
        }

        $convenios = $this->convenios($unidades);
        foreach ($this->deudas($unidades) as $id => $deuda) {
            if ($this->enMora($deuda, $convenios[$id] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Levanta la restricción abierta de una unidad si ya no corresponde (pagó o firmó un convenio).
     * Se llama al aprobar un pago y al crear un convenio; la tarea programada hace lo mismo cada hora.
     */
    public function reevaluar(int $unidadId): bool
    {
        $abierta = RestriccionMora::query()->where('unidad_id', $unidadId)->whereIn('estado', [RestriccionMora::NOTIFICADA, RestriccionMora::ACTIVA])->lockForUpdate()->first();
        if ($abierta === null) {
            return false;
        }

        $deuda = $this->deudas([$unidadId])[$unidadId] ?? null;
        $convenio = $this->convenios([$unidadId])[$unidadId] ?? null;
        if ($deuda !== null && $this->debeRestringirse($deuda, $convenio)) {
            return false;
        }

        $abierta->forceFill([
            'estado' => RestriccionMora::LEVANTADA, 'levantada_en' => now(),
            'motivo_levantamiento' => $convenio !== null && $convenio['al_dia'] ? 'convenio' : 'pago',
        ])->save();

        return true;
    }
}
