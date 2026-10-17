<?php

namespace App\Modules\Garita\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Caso de uso: un residente anuncia una visita para una de sus unidades. «Una vez» vale un solo ingreso en esa fecha;
 * «recurrente» vale los días de la semana elegidos hasta una fecha (máximo un año). Devuelve la autorización con su QR.
 */
final class CrearAutorizacionVisitaAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly TenantContext $tenant,
        private readonly UnidadesDeCuentaAction $unidades,
    ) {}

    /**
     * @param  array{unidad_id: int, visitante: string, documento?: string|null, placa?: string|null, tipo: string, fecha_desde: string, fecha_hasta?: string|null, hora_desde: string, hora_hasta: string, dias_semana?: list<int>|null}  $d
     */
    public function execute(User $user, array $d): AutorizacionVisita
    {
        $mias = collect($this->unidades->execute($user->id))->keyBy('unidad_id');
        if (! $mias->has($d['unidad_id'])) {
            throw new ApiException('UNIDAD_NO_ES_SUYA', 'Solo puedes autorizar visitas a una unidad en la que vives.', 403);
        }
        if ($d['hora_desde'] >= $d['hora_hasta']) {
            throw new ApiException('HORAS_INVERTIDAS', 'La hora final debe ser posterior a la inicial.', 422);
        }

        $hoy = $this->calendario->hoy();
        $recurrente = $d['tipo'] === AutorizacionVisita::RECURRENTE;
        $hasta = $recurrente ? ($d['fecha_hasta'] ?? $d['fecha_desde']) : $d['fecha_desde'];
        $dias = null;

        if ($d['fecha_desde'] < $hoy) {
            throw new ApiException('FECHA_PASADA', 'La fecha no puede ser anterior a hoy.', 422);
        }
        if ($hasta < $d['fecha_desde']) {
            throw new ApiException('FECHAS_INVERTIDAS', 'La fecha final debe ser posterior a la inicial.', 422);
        }
        if ($recurrente) {
            $dias = array_values(array_unique(array_map('intval', $d['dias_semana'] ?? [])));
            sort($dias);
            if ($dias === []) {
                throw new ApiException('SIN_DIAS', 'Elige al menos un día de la semana.', 422);
            }
            if ($hasta > CarbonImmutable::parse($hoy)->addYear()->toDateString()) {
                throw new ApiException('VIGENCIA_MUY_LARGA', 'Una visita recurrente puede durar hasta un año.', 422);
            }
        } elseif ($d['fecha_desde'] === $hoy && $d['hora_hasta'] <= CarbonImmutable::now($this->calendario->zonaHoraria())->format('H:i')) {
            throw new ApiException('HORARIO_PASADO', 'Ese horario de hoy ya pasó.', 422);
        }

        $condominioId = $this->tenant->require();

        return DB::transaction(function () use ($user, $d, $hasta, $dias, $hoy, $condominioId): AutorizacionVisita {
            $activas = AutorizacionVisita::query()->where('unidad_id', $d['unidad_id'])->whereNull('revocada_en')
                ->whereDate('fecha_hasta', '>=', $hoy)->lockForUpdate()->get()
                ->filter(fn (AutorizacionVisita $a): bool => $a->estadoEn($hoy) === 'activa')->count();
            if ($activas >= AutorizacionVisita::MAXIMO_ACTIVAS) {
                throw new ApiException('LIMITE_AUTORIZACIONES', 'Tu unidad ya tiene el máximo de visitas autorizadas. Revoca alguna para crear otra.', 409);
            }

            return AutorizacionVisita::create([
                'unidad_id' => $d['unidad_id'], 'creada_por' => $user->id,
                // El condominio va en el token: la página pública del QR lo necesita para buscarlo sin sesión
                'token' => $condominioId.'-'.Str::random(40),
                'visitante_nombre' => $d['visitante'], 'visitante_documento' => $d['documento'] ?? null,
                'placa' => isset($d['placa']) && $d['placa'] !== '' ? strtoupper($d['placa']) : null,
                'tipo' => $d['tipo'], 'fecha_desde' => $d['fecha_desde'], 'fecha_hasta' => $hasta,
                'hora_desde' => $d['hora_desde'], 'hora_hasta' => $d['hora_hasta'], 'dias_semana' => $dias,
            ]);
        });
    }
}
