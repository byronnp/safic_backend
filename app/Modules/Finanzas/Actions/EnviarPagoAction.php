<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Services\SaldoFavorService;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del residente: informar una transferencia con su comprobante. Elige las cuotas que
 * paga (siempre desde la más antigua y completas); el monto a transferir es lo que suman menos
 * su saldo a favor. Queda pendiente hasta que la administración lo apruebe: no cambia ninguna
 * cuota todavía. Una cuota ya incluida en otro pago en revisión no se puede volver a elegir.
 */
final class EnviarPagoAction
{
    public function __construct(
        private readonly UnidadesDeCuentaAction $unidades,
        private readonly SaldoFavorService $saldoFavor,
        private readonly ArchivosCondominio $archivos,
        private readonly Calendario $calendario,
    ) {}

    /**
     * @param  list<int>  $cuotaIds
     */
    public function execute(User $user, int $unidadId, array $cuotaIds, string $numeroComprobante, UploadedFile $comprobante): Pago
    {
        $unidad = collect($this->unidades->execute($user->id))->firstWhere('unidad_id', $unidadId)
            ?? throw new ApiException('UNIDAD_NO_ES_TUYA', 'Esa unidad no es de tu cuenta.', 403);

        if (! $unidad['puede_pagar']) {
            throw new ApiException('NO_PUEDES_PAGAR_ESTA_UNIDAD', 'Los pagos de esta unidad los hace su responsable de pago.', 403);
        }

        $cuenta = CuentaBancaria::query()->where('activa', true)->where('es_principal', true)->first()
            ?? throw new ApiException('SIN_CUENTA_BANCARIA', 'La administración aún no configuró la cuenta para transferir. Avísale.', 409);

        $ruta = $this->archivos->guardar($comprobante, 'comprobantes');

        try {
            return DB::transaction(fn (): Pago => $this->registrar($user, $unidad, $cuenta, $cuotaIds, trim($numeroComprobante), $ruta));
        } catch (UniqueConstraintViolationException) {
            $this->archivos->eliminar($ruta);

            throw new ApiException('COMPROBANTE_DUPLICADO', 'Este número de comprobante ya fue registrado.', 409);
        } catch (\Throwable $e) {
            $this->archivos->eliminar($ruta);

            throw $e;
        }
    }

    /**
     * @param  array{unidad_id: int, codigo: string, relacion: string, puede_pagar: bool}  $unidad
     * @param  list<int>  $cuotaIds
     */
    private function registrar(User $user, array $unidad, CuentaBancaria $cuenta, array $cuotaIds, string $numero, string $ruta): Pago
    {
        // Bloquea las cuotas con saldo de la unidad: dos envíos a la vez no eligen las mismas
        $pendientes = Cuota::query()
            ->where('unidad_id', $unidad['unidad_id'])
            ->whereColumn('monto', '>', 'pagado')
            ->orderBy('periodo')->orderBy('id')
            ->lockForUpdate()
            ->get();

        $enRevision = ObtenerMiCuentaAction::cuotasEnRevision($unidad['unidad_id']);
        $pedidas = array_values(array_unique($cuotaIds));

        if (array_intersect($pedidas, $enRevision) !== []) {
            throw new ApiException('CUOTAS_EN_REVISION', 'Alguna de esas cuotas ya está en un pago en revisión.', 409);
        }

        // Las pedidas deben ser, en orden, las primeras cuotas libres (de la más antigua en adelante)
        $libres = $pendientes->reject(fn (Cuota $c) => in_array($c->id, $enRevision, true))->values();
        $esperadas = $libres->take(count($pedidas))->pluck('id')->all();
        if ($pedidas === [] || count($pedidas) > $libres->count() || array_diff($pedidas, $esperadas) !== []) {
            throw new ApiException('CUOTAS_NO_VALIDAS', 'Se paga desde la cuota más antigua y cada cuota completa.', 422);
        }

        $elegidas = $libres->take(count($pedidas));
        $total = Dinero::suma($elegidas->map(fn (Cuota $c) => Dinero::texto(Dinero::centavos($c->monto) - Dinero::centavos($c->pagado))));
        $credito = Dinero::centavos($this->saldoFavor->de($unidad['unidad_id']));
        $monto = $total - min($credito, $total);

        if ($monto <= 0) {
            throw new ApiException('NADA_QUE_TRANSFERIR', 'Tu saldo a favor cubre esas cuotas: no necesitas transferir.', 409);
        }

        $duplicado = Pago::query()->whereRaw('lower(numero_comprobante) = ?', [mb_strtolower($numero)])
            ->whereNotIn('estado', [Pago::RECHAZADO, Pago::ANULADO])->first();
        if ($duplicado !== null) {
            throw new ApiException('COMPROBANTE_DUPLICADO', 'Este número de comprobante ya fue registrado el '.$duplicado->fecha_pago->format('d/m/Y').'.', 409);
        }

        return Pago::create([
            'unidad_id' => $unidad['unidad_id'],
            'user_id' => $user->id,
            'metodo' => 'transferencia',
            'monto' => Dinero::texto($monto),
            'cuenta_bancaria_id' => $cuenta->id,
            'numero_comprobante' => $numero,
            'fecha_pago' => $this->calendario->hoy(),
            'comprobante_ruta' => $ruta,
            'estado' => Pago::PENDIENTE,
            'cuotas_declaradas' => $elegidas->map(fn (Cuota $c): array => [
                'cuota_id' => $c->id,
                'periodo' => $c->periodo->format('Y-m'),
                'monto' => Dinero::texto(Dinero::centavos($c->monto) - Dinero::centavos($c->pagado)),
            ])->all(),
        ]);
    }
}
