<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finanzas\Mail\RestriccionAreasMail;
use App\Modules\Finanzas\Models\ConvenioPago;
use App\Modules\Finanzas\Models\RestriccionMora;
use App\Modules\Finanzas\Services\MorosidadService;
use App\Modules\Unidades\Actions\CodigosDeUnidadesAction;
use App\Modules\Unidades\Actions\ContactosDePagoAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * La tarea horaria del Decreto 462 en el condominio activo:
 * 1. levanta las restricciones que ya no corresponden (pagó o firmó un convenio);
 * 2. pone en vigor las notificadas cuyo plazo de aviso venció;
 * 3. notifica, con el detalle de la deuda, a las unidades que llegaron al límite de meses;
 * 4. da por cumplidos los convenios pagados por completo.
 * Devuelve cuántas se notificaron, activaron y levantaron.
 */
final class ActualizarRestriccionesAction
{
    public function __construct(
        private readonly MorosidadService $morosidad,
        private readonly ContactosDePagoAction $contactos,
        private readonly CodigosDeUnidadesAction $codigos,
        private readonly Calendario $calendario,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array{notificadas: int, activadas: int, levantadas: int} */
    public function execute(): array
    {
        $hoy = $this->calendario->hoy();
        $r = ['notificadas' => 0, 'activadas' => 0, 'levantadas' => 0];

        DB::transaction(function () use ($hoy, &$r): void {
            foreach ($this->morosidad->convenios() as $c) {
                if ($c['pagado'] >= Dinero::centavos((string) $c['convenio']->monto_total)) {
                    $c['convenio']->forceFill(['estado' => ConvenioPago::CUMPLIDO])->save();
                }
            }

            foreach (RestriccionMora::query()->whereIn('estado', [RestriccionMora::NOTIFICADA, RestriccionMora::ACTIVA])->pluck('unidad_id') as $unidadId) {
                if ($this->morosidad->reevaluar((int) $unidadId)) {
                    $r['levantadas']++;
                }
            }

            $r['activadas'] = RestriccionMora::query()->where('estado', RestriccionMora::NOTIFICADA)->where('restringe_desde', '<=', $hoy)
                ->update(['estado' => RestriccionMora::ACTIVA]);

            $deudas = $this->morosidad->deudas();
            $abiertas = RestriccionMora::query()->whereIn('estado', [RestriccionMora::NOTIFICADA, RestriccionMora::ACTIVA])->pluck('unidad_id')->flip();
            $convenios = $this->morosidad->convenios(array_keys($deudas));
            $nuevas = array_filter($deudas, fn (array $d, int $id) => ! $abiertas->has($id) && $this->morosidad->debeRestringirse($d, $convenios[$id] ?? null), ARRAY_FILTER_USE_BOTH);

            $contactos = $this->contactos->execute(array_keys($nuevas));
            $codigos = $this->codigos->execute(array_keys($nuevas));
            $desde = CarbonImmutable::parse($hoy)->addDays($this->morosidad->reglas()['dias_aviso_restriccion']);
            $condominio = (string) DB::table('condominios')->where('id', $this->tenant->require())->value('nombre');

            foreach ($nuevas as $id => $d) {
                RestriccionMora::create([
                    'unidad_id' => $id, 'estado' => RestriccionMora::NOTIFICADA, 'notificada_en' => now(),
                    'restringe_desde' => $desde->toDateString(), 'deuda_al_notificar' => Dinero::texto($d['saldo']), 'meses_al_notificar' => $d['meses'],
                ]);
                $r['notificadas']++;

                $email = $contactos[$id]['email'] ?? null;
                if ($email !== null && $email !== '') {
                    Mail::to($email)->queue(new RestriccionAreasMail(
                        nombre: $contactos[$id]['nombre'], condominio: $condominio, unidad: $codigos[$id] ?? '', saldo: Dinero::texto($d['saldo']),
                        cuotas: $d['cuotas'], meses: $d['meses'], restringeDesde: $desde->locale('es')->translatedFormat('j \d\e F \d\e Y'),
                    ));
                }
            }
        });

        return $r;
    }
}
