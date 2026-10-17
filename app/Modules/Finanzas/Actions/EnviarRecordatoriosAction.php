<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Finanzas\Mail\RecordatorioCuotaMail;
use App\Modules\Finanzas\Models\RecordatorioMora;
use App\Modules\Finanzas\Services\MorosidadService;
use App\Modules\Unidades\Actions\CodigosDeUnidadesAction;
use App\Modules\Unidades\Actions\ContactosDePagoAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Recordatorios de cuota por correo al responsable de pago. Dos caminos:
 * - manual («Recordar a todos» o a unas unidades): a quien tiene cuotas vencidas, una vez al día;
 * - automático (tarea diaria): 3 días antes del vencimiento, el día, y cada 15 días de atraso.
 * Nada se envía dos veces el mismo día para lo mismo, y quien no tiene correo se cuenta aparte.
 */
final class EnviarRecordatoriosAction
{
    public const DIAS_ANTES = 3;

    public const CADA_DIAS_DE_ATRASO = 15;

    public function __construct(
        private readonly MorosidadService $morosidad,
        private readonly ContactosDePagoAction $contactos,
        private readonly CodigosDeUnidadesAction $codigos,
        private readonly Calendario $calendario,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  list<int>|null  $unidadIds  null = todas las que deben
     * @return array{enviados: int, sin_correo: int, ya_avisadas: int}
     */
    public function manual(User $user, ?array $unidadIds): array
    {
        $hoy = $this->calendario->hoy();
        $deudas = $this->morosidad->deudas($unidadIds);
        $ids = array_keys($deudas);
        $contactos = $this->contactos->execute($ids);
        $codigos = $this->codigos->execute($ids);
        $yaHoy = RecordatorioMora::query()->where('tipo', 'manual')->where('referencia', $hoy)->whereIn('unidad_id', $ids)->pluck('unidad_id')->flip();

        $r = ['enviados' => 0, 'sin_correo' => 0, 'ya_avisadas' => 0];
        foreach ($deudas as $id => $d) {
            if ($yaHoy->has($id)) {
                $r['ya_avisadas']++;

                continue;
            }
            $email = $contactos[$id]['email'] ?? null;
            if ($email === null || $email === '') {
                $r['sin_correo']++;

                continue;
            }

            $this->enviar($id, $email, $contactos[$id]['nombre'], $codigos[$id] ?? '', 'atraso', $d['saldo'], $d['primera'], $d['cuotas']);
            RecordatorioMora::create(['unidad_id' => $id, 'tipo' => 'manual', 'referencia' => $hoy, 'enviado_a' => $email, 'enviado_por' => $user->id]);
            $r['enviados']++;
        }

        return $r;
    }

    /**
     * La tarea diaria del condominio activo. Devuelve cuántos avisos salieron.
     */
    public function automaticos(): int
    {
        $hoy = CarbonImmutable::parse($this->calendario->hoy());
        $antes = $hoy->addDays(self::DIAS_ANTES)->toDateString();

        // Por vencer (en 3 días) y que vencen hoy: lo que vence ese día, por unidad
        $porVencer = DB::table('cuotas')->whereColumn('monto', '>', 'pagado')->whereIn('vence_el', [$antes, $hoy->toDateString()])
            ->groupBy('unidad_id', 'vence_el')->selectRaw('unidad_id, vence_el, count(*) as cuotas, sum(monto - pagado) as saldo')->get();

        $aviso = [];
        foreach ($porVencer as $f) {
            $aviso[] = [(int) $f->unidad_id, (string) $f->vence_el === $antes ? 'antes' : 'dia', (string) $f->vence_el, (int) $f->cuotas, Dinero::centavos((string) $f->saldo)];
        }
        // Con atraso: cada 15 días desde la cuota vencida más antigua
        foreach ($this->morosidad->deudas() as $id => $d) {
            if ($d['dias'] > 0 && $d['dias'] % self::CADA_DIAS_DE_ATRASO === 0) {
                $aviso[] = [$id, 'atraso', $hoy->toDateString(), $d['cuotas'], $d['saldo']];
            }
        }
        if ($aviso === []) {
            return 0;
        }

        $ids = array_values(array_unique(array_column($aviso, 0)));
        $contactos = $this->contactos->execute($ids);
        $codigos = $this->codigos->execute($ids);

        $enviados = 0;
        foreach ($aviso as [$id, $tipo, $referencia, $cuotas, $saldo]) {
            $email = $contactos[$id]['email'] ?? null;
            if ($email === null || $email === '') {
                continue;
            }
            try {
                // Dentro de una transacción propia (punto de guardado): un duplicado no aborta la del llamador
                DB::transaction(fn () => RecordatorioMora::create(['unidad_id' => $id, 'tipo' => $tipo, 'referencia' => $referencia, 'enviado_a' => $email]));
            } catch (UniqueConstraintViolationException) {
                continue; // ya se avisó esto
            }
            $this->enviar($id, $email, $contactos[$id]['nombre'], $codigos[$id] ?? '', $tipo, $saldo, $referencia, $cuotas);
            $enviados++;
        }

        return $enviados;
    }

    private function enviar(int $unidadId, string $email, string $nombre, string $codigo, string $tipo, int $saldo, string $vence, int $cuotas): void
    {
        $condominio = (string) DB::table('condominios')->where('id', $this->tenant->require())->value('nombre');

        Mail::to($email)->queue(new RecordatorioCuotaMail(
            nombre: $nombre, condominio: $condominio, unidad: $codigo, tipo: $tipo,
            saldo: Dinero::texto($saldo), vence: CarbonImmutable::parse($vence)->locale('es')->translatedFormat('j \d\e F'), cuotas: $cuotas,
        ));
    }
}
