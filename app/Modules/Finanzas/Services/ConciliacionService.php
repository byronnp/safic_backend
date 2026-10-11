<?php

namespace App\Modules\Finanzas\Services;

use App\Core\Support\Dinero;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finanzas\Models\MovimientoBancario;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Models\PagoProveedor;
use App\Modules\Finanzas\Models\ReglaConciliacion;
use App\Modules\Unidades\Actions\CodigosDeUnidadesAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clasifica las líneas de un estado de cuenta:
 * - `automatico`: cruzó sin duda con un pago (monto exacto y, además, el código de la unidad o la
 *   referencia del banco), o coincide con una regla guardada.
 * - `sugerido`: cruza por monto (un pago candidato) o parece una comisión o un interés; alguien confirma.
 * - `sin_identificar`: nada lo explica; alguien lo clasifica.
 * Un pago ya vinculado a otra línea no vuelve a ofrecerse.
 */
final class ConciliacionService
{
    /** Días antes del mes y después de su fin en que se buscan pagos: el banco acredita con retraso. */
    private const MARGEN_DIAS = 7;

    public function __construct(
        private readonly CodigosDeUnidadesAction $codigos,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Clasifica las líneas dadas (que aún no están resueltas) y las guarda.
     *
     * @param  Collection<int, MovimientoBancario>  $movimientos
     */
    public function clasificar(Collection $movimientos, int $cuentaBancariaId, CarbonImmutable $mes): void
    {
        $pendientes = $movimientos->reject(fn (MovimientoBancario $m) => $m->estado === MovimientoBancario::CONCILIADO);
        if ($pendientes->isEmpty()) {
            return;
        }

        $desde = $mes->subDays(self::MARGEN_DIAS)->toDateString();
        $hasta = $mes->endOfMonth()->addDays(self::MARGEN_DIAS)->toDateString();
        $reglas = ReglaConciliacion::query()->get();

        $usadosPagos = MovimientoBancario::query()->whereNotNull('pago_id')->pluck('pago_id')->all();
        $usadosProveedor = MovimientoBancario::query()->whereNotNull('pago_proveedor_id')->pluck('pago_proveedor_id')->all();

        /** @var Collection<int, Pago> $pagos */
        $pagos = Pago::query()->where('estado', Pago::APROBADO)->whereNotIn('id', $usadosPagos)
            ->whereBetween('fecha_pago', [$desde, $hasta])
            ->where(fn ($q) => $q->whereNull('cuenta_bancaria_id')->orWhere('cuenta_bancaria_id', $cuentaBancariaId))->get();
        $codigosUnidad = $this->codigos->execute($pagos->pluck('unidad_id')->all());
        $codigoCondominio = (string) DB::table('condominios')->where('id', $this->tenant->require())->value('codigo');

        /** @var Collection<int, PagoProveedor> $proveedor */
        $proveedor = PagoProveedor::query()->where('estado', 'registrado')->whereNotIn('id', $usadosProveedor)
            ->whereBetween('fecha_pago', [$desde, $hasta])
            ->where(fn ($q) => $q->whereNull('cuenta_bancaria_id')->orWhere('cuenta_bancaria_id', $cuentaBancariaId))->with('proveedor:id,razon_social')->get();

        // Un pago se ofrece a una sola línea: se van descartando los que ya se asignaron en esta pasada
        foreach ($pendientes->sortBy('fecha') as $m) {
            $this->reiniciar($m);
            $credito = Dinero::centavos($m->monto) > 0;
            $centavos = abs(Dinero::centavos($m->monto));

            $resultado = $credito
                ? $this->cruzarCredito($m, $centavos, $pagos, $codigosUnidad, $codigoCondominio)
                : $this->cruzarDebito($m, $centavos, $proveedor);

            $resultado ??= $this->porRegla($m, $reglas, $credito) ?? $this->porPalabra($m, $credito);

            $m->fill($resultado ?? ['estado' => MovimientoBancario::SIN_IDENTIFICAR])->save();

            if (($resultado['estado'] ?? null) === MovimientoBancario::AUTOMATICO && ($resultado['pago_id'] ?? null)) {
                $pagos = $pagos->reject(fn (Pago $p) => $p->id === $resultado['pago_id']);
            }
            if (($resultado['estado'] ?? null) === MovimientoBancario::AUTOMATICO && ($resultado['pago_proveedor_id'] ?? null)) {
                $proveedor = $proveedor->reject(fn (PagoProveedor $p) => $p->id === $resultado['pago_proveedor_id']);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function cruzarCredito(MovimientoBancario $m, int $centavos, Collection $pagos, array $codigosUnidad, string $codigoCondominio): ?array
    {
        $candidatos = $pagos->filter(fn (Pago $p) => Dinero::centavos($p->monto) === $centavos);
        if ($candidatos->isEmpty()) {
            return null;
        }

        $fuertes = $candidatos->filter(fn (Pago $p) => $this->mencionaCodigo($m->descripcion, $codigoCondominio, $codigosUnidad[$p->unidad_id] ?? '')
            || $this->mismaReferencia($m->referencia, $p->numero_comprobante));
        if ($fuertes->count() === 1) {
            return ['estado' => MovimientoBancario::AUTOMATICO, 'tipo' => 'pago_residente', 'pago_id' => $fuertes->first()->id];
        }

        $elegido = $candidatos->sortBy(fn (Pago $p) => abs($p->fecha_pago->diffInDays($m->fecha)))->first();

        return [
            'estado' => MovimientoBancario::SUGERIDO, 'propuesta_tipo' => 'pago_residente', 'propuesta_id' => $elegido->id,
            'propuesta_texto' => 'Pago aprobado de '.($codigosUnidad[$elegido->unidad_id] ?? 'una unidad').' (mismo monto'.($candidatos->count() > 1 ? ', hay otros iguales: revisa' : '').')',
        ];
    }

    /** @return array<string, mixed>|null */
    private function cruzarDebito(MovimientoBancario $m, int $centavos, Collection $proveedor): ?array
    {
        $candidatos = $proveedor->filter(fn (PagoProveedor $p) => Dinero::centavos($p->monto) === $centavos);
        if ($candidatos->isEmpty()) {
            return null;
        }

        $fuertes = $candidatos->filter(fn (PagoProveedor $p) => $this->mismaReferencia($m->referencia, $p->referencia));
        if ($fuertes->count() === 1) {
            return ['estado' => MovimientoBancario::AUTOMATICO, 'tipo' => 'pago_proveedor', 'pago_proveedor_id' => $fuertes->first()->id];
        }

        $elegido = $candidatos->sortBy(fn (PagoProveedor $p) => abs($p->fecha_pago->diffInDays($m->fecha)))->first();

        return [
            'estado' => MovimientoBancario::SUGERIDO, 'propuesta_tipo' => 'pago_proveedor', 'propuesta_id' => $elegido->id,
            'propuesta_texto' => 'Pago '.$elegido->codigo().' a '.$elegido->proveedor->razon_social.' (mismo monto)',
        ];
    }

    /** @return array<string, mixed>|null */
    private function porRegla(MovimientoBancario $m, Collection $reglas, bool $credito): ?array
    {
        $descripcion = mb_strtoupper($m->descripcion);
        $regla = $reglas->first(fn (ReglaConciliacion $r) => str_contains($descripcion, mb_strtoupper($r->patron)));

        return $regla === null ? null : ['estado' => MovimientoBancario::AUTOMATICO, 'tipo' => $regla->tipo];
    }

    /** @return array<string, mixed>|null */
    private function porPalabra(MovimientoBancario $m, bool $credito): ?array
    {
        $d = mb_strtoupper($m->descripcion);
        if (! $credito && (str_contains($d, 'COMISION') || str_contains($d, 'COMIS ') || str_contains($d, 'COSTO MANT'))) {
            return ['estado' => MovimientoBancario::SUGERIDO, 'propuesta_tipo' => 'comision', 'propuesta_texto' => 'Comisión bancaria'];
        }
        if ($credito && str_contains($d, 'INTERES')) {
            return ['estado' => MovimientoBancario::SUGERIDO, 'propuesta_tipo' => 'interes', 'propuesta_texto' => 'Interés ganado'];
        }

        return null;
    }

    /** ¿La descripción del banco trae el código de transferencia de la unidad (JV-A102, JVA102…)? */
    private function mencionaCodigo(string $descripcion, string $codigoCondominio, string $codigoUnidad): bool
    {
        if ($codigoCondominio === '' || $codigoUnidad === '') {
            return false;
        }

        $unidad = preg_replace('/[\s-]+/', '', $codigoUnidad) ?? '';
        $patron = '/(?<![A-Z0-9])'.preg_quote(mb_strtoupper($codigoCondominio), '/').'[\s-]?'.preg_quote(mb_strtoupper($unidad), '/').'(?![A-Z0-9])/u';

        return preg_match($patron, mb_strtoupper($descripcion)) === 1;
    }

    private function mismaReferencia(?string $banco, ?string $registrada): bool
    {
        if ($banco === null || $registrada === null) {
            return false;
        }
        $a = ltrim(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $banco) ?? ''), '0');
        $b = ltrim(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $registrada) ?? ''), '0');

        return $a !== '' && $a === $b;
    }

    private function reiniciar(MovimientoBancario $m): void
    {
        $m->fill([
            'tipo' => null, 'pago_id' => null, 'pago_proveedor_id' => null,
            'propuesta_tipo' => null, 'propuesta_id' => null, 'propuesta_texto' => null,
            'conciliado_por' => null, 'conciliado_en' => null,
        ]);
    }
}
