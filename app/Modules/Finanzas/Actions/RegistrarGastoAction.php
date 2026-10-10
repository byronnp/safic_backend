<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Core\Support\Dinero;
use App\Core\Support\FacturaSri;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\Proveedor;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: registrar una factura de proveedor como cuenta por pagar, desde el XML del SRI o a
 * mano. Nace «por aprobar»: quien la registra no la aprueba. El proveedor se crea o se reutiliza
 * por RUC. La misma factura no entra dos veces (clave de acceso o proveedor + número).
 */
final class RegistrarGastoAction
{
    /** Plazo de pago cuando la factura no lo dice (el XML del SRI no trae fecha de vencimiento). */
    public const PLAZO_POR_DEFECTO_DIAS = 30;

    public function __construct(
        private readonly ArchivosCondominio $archivos,
        private readonly Calendario $calendario,
    ) {}

    /**
     * @param  array{vence_el?: string|null, categoria?: string|null}  $extra
     */
    public function desdeXml(User $user, UploadedFile $xml, ?UploadedFile $pdf, array $extra): Gasto
    {
        $contenido = (string) file_get_contents($xml->getRealPath());
        $f = FacturaSri::leer($contenido);

        return $this->guardar($user, [
            'proveedor' => fn (): Proveedor => $this->proveedorDeXml($f['ruc'], $f['razon_social']),
            'numero' => $f['numero'], 'clave_acceso' => $f['clave_acceso'], 'origen' => 'xml',
            'categoria' => $extra['categoria'] ?? null, 'descripcion' => $f['descripcion'],
            'fecha_emision' => $f['fecha_emision'], 'vence_el' => $extra['vence_el'] ?? null,
            'subtotal' => $f['subtotal'], 'iva' => $f['iva'], 'total' => $f['total'],
        ], $xml, $pdf);
    }

    /**
     * @param  array{proveedor_id: int, numero: string, fecha_emision: string, vence_el?: string|null, subtotal: string, con_iva: bool, categoria?: string|null, descripcion?: string|null}  $d
     */
    public function manual(User $user, array $d, ?UploadedFile $pdf): Gasto
    {
        $subtotal = Dinero::centavos($d['subtotal']);
        $iva = FacturaSri::ivaDe($subtotal, $d['con_iva']);

        return $this->guardar($user, [
            'proveedor' => fn (): Proveedor => Proveedor::query()->where('activo', true)->findOrFail($d['proveedor_id']),
            'numero' => $d['numero'], 'clave_acceso' => null, 'origen' => 'manual',
            'categoria' => $d['categoria'] ?? null, 'descripcion' => $d['descripcion'] ?? null,
            'fecha_emision' => $d['fecha_emision'], 'vence_el' => $d['vence_el'] ?? null,
            'subtotal' => Dinero::texto($subtotal), 'iva' => Dinero::texto($iva), 'total' => Dinero::texto($subtotal + $iva),
        ], null, $pdf);
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function guardar(User $user, array $d, ?UploadedFile $xml, ?UploadedFile $pdf): Gasto
    {
        $emision = CarbonImmutable::parse($d['fecha_emision']);
        if ($emision->isAfter($this->calendario->hoy())) {
            throw new ApiException('FECHA_FUTURA', 'La fecha de emisión no puede ser futura.', 422);
        }
        $vence = $d['vence_el'] ?? $emision->addDays(self::PLAZO_POR_DEFECTO_DIAS)->toDateString();
        if (CarbonImmutable::parse($vence)->isBefore($emision)) {
            throw new ApiException('VENCIMIENTO_INVALIDO', 'El vencimiento no puede ser anterior a la emisión.', 422);
        }

        $rutas = [];
        try {
            if ($xml !== null) {
                $rutas['xml_ruta'] = $this->archivos->guardar($xml, 'facturas');
            }
            if ($pdf !== null) {
                $rutas['pdf_ruta'] = $this->archivos->guardar($pdf, 'facturas');
            }

            return DB::transaction(function () use ($user, $d, $vence, $rutas): Gasto {
                /** @var Proveedor $proveedor */
                $proveedor = ($d['proveedor'])();
                if (! $proveedor->activo) {
                    throw new ApiException('PROVEEDOR_INACTIVO', 'Ese proveedor está desactivado.', 422);
                }

                return Gasto::create($rutas + [
                    'proveedor_id' => $proveedor->id,
                    'numero' => $d['numero'], 'clave_acceso' => $d['clave_acceso'], 'origen' => $d['origen'],
                    'categoria' => $d['categoria'] ?? $proveedor->categoria, 'descripcion' => $d['descripcion'],
                    'fecha_emision' => $d['fecha_emision'], 'vence_el' => $vence,
                    'subtotal' => $d['subtotal'], 'iva' => $d['iva'], 'total' => $d['total'],
                    'pagado' => '0.00', 'estado' => 'por_aprobar', 'registrado_por' => $user->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->limpiar($rutas);

            throw new ApiException('FACTURA_DUPLICADA', 'Esta factura ya está registrada.', 409);
        } catch (\Throwable $e) {
            $this->limpiar($rutas);

            throw $e;
        }
    }

    private function proveedorDeXml(string $ruc, string $razonSocial): Proveedor
    {
        return Proveedor::query()->where('ruc', $ruc)->first()
            ?? Proveedor::create(['ruc' => $ruc, 'razon_social' => $razonSocial, 'activo' => true]);
    }

    /** @param  array<string, string>  $rutas */
    private function limpiar(array $rutas): void
    {
        foreach ($rutas as $ruta) {
            $this->archivos->eliminar($ruta);
        }
    }
}
