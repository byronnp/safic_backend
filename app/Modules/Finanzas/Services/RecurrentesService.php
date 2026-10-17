<?php

namespace App\Modules\Finanzas\Services;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Mail\FacturaPorAdjuntarMail;
use App\Modules\Finanzas\Models\GastoRecurrente;
use App\Modules\Finanzas\Models\RecurrenteOcurrencia;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Las ocurrencias mensuales de los gastos recurrentes: a partir de su día de cada mes se crea la
 * del mes en curso y se avisa que falta la factura. Crear dos veces la misma no es posible (índice
 * único por recurrente y mes).
 */
final class RecurrentesService
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly DestinatariosFinanzas $destinatarios,
    ) {}

    /** Crea la ocurrencia del mes en curso de los recurrentes activos a los que ya les tocó. Devuelve cuántas creó. */
    public function generar(?GastoRecurrente $solo = null): int
    {
        $hoy = CarbonImmutable::parse($this->calendario->hoy());
        $creadas = 0;

        $recurrentes = GastoRecurrente::query()->with('proveedor:id,razon_social')->where('activo', true)
            ->when($solo !== null, fn ($q) => $q->whereKey($solo->id))->where('dia_mes', '<=', $hoy->day)->get();

        foreach ($recurrentes as $r) {
            try {
                $o = DB::transaction(fn () => RecurrenteOcurrencia::create([
                    'gasto_recurrente_id' => $r->id, 'periodo' => $hoy->startOfMonth()->toDateString(),
                    'fecha_prevista' => $hoy->startOfMonth()->day($r->dia_mes)->toDateString(),
                ]));
            } catch (UniqueConstraintViolationException) {
                continue; // ya existía la de este mes
            }

            $creadas++;
            $this->avisar($r, $o);
        }

        return $creadas;
    }

    private function avisar(GastoRecurrente $r, RecurrenteOcurrencia $o): void
    {
        $condominio = (string) DB::table('condominios')->where('id', $r->condominio_id)->value('nombre');

        foreach ($this->destinatarios->correos() as $correo) {
            Mail::to($correo)->queue(new FacturaPorAdjuntarMail(
                condominio: $condominio, gasto: $r->nombre, proveedor: $r->proveedor->razon_social,
                monto: $r->monto === null ? null : Dinero::texto(Dinero::centavos((string) $r->monto)),
                fecha: $o->fecha_prevista->locale('es')->translatedFormat('j \d\e F'),
            ));
        }
        $o->forceFill(['avisada_en' => now()])->save();
    }
}
