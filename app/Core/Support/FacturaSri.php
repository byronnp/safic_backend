<?php

namespace App\Core\Support;

use App\Core\Http\Exceptions\ApiException;

/**
 * Lee el XML de una factura electrónica del SRI (con o sin el envoltorio `<autorizacion>`).
 * Solo valida lo que el documento dice de sí mismo (estructura, RUC, clave de acceso con su dígito
 * verificador y que los totales cuadren); no consulta al SRI. Nunca resuelve entidades externas.
 */
final class FacturaSri
{
    private const TASA_IVA = 15;

    /**
     * @return array{ruc: string, razon_social: string, numero: string, clave_acceso: string, fecha_emision: string, subtotal: string, iva: string, total: string, descripcion: string|null}
     */
    public static function leer(string $xml): array
    {
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw self::invalido('El archivo no es una factura del SRI válida.');
        }

        $previo = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, options: LIBXML_NONET);
            if ($doc === false) {
                throw self::invalido('El archivo no es un XML válido.');
            }

            // Autorizado por el SRI: la factura viene como texto dentro de <comprobante>
            if ($doc->getName() === 'autorizacion') {
                $interno = (string) $doc->comprobante;
                $doc = $interno !== '' && preg_match('/<!(DOCTYPE|ENTITY)/i', $interno) !== 1
                    ? simplexml_load_string($interno, options: LIBXML_NONET)
                    : false;
                if ($doc === false) {
                    throw self::invalido('No se pudo leer la factura dentro de la autorización.');
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previo);
        }

        if ($doc->getName() !== 'factura') {
            throw self::invalido('El XML no es una factura (¿es una nota de crédito o una retención?).');
        }

        $t = $doc->infoTributaria;
        $f = $doc->infoFactura;
        $ruc = trim((string) $t->ruc);
        $clave = trim((string) $t->claveAcceso);
        $numero = sprintf('%03d-%03d-%09d', (int) $t->estab, (int) $t->ptoEmi, (int) $t->secuencial);

        if (preg_match('/^\d{13}$/', $ruc) !== 1) {
            throw self::invalido('El RUC de la factura no es válido.');
        }
        if (! self::claveValida($clave)) {
            throw self::invalido('La clave de acceso de la factura no es válida.');
        }

        $fecha = \DateTimeImmutable::createFromFormat('!d/m/Y', trim((string) $f->fechaEmision));
        if ($fecha === false) {
            throw self::invalido('La fecha de emisión de la factura no es válida.');
        }

        $subtotal = Dinero::centavos(trim((string) $f->totalSinImpuestos));
        $total = Dinero::centavos(trim((string) $f->importeTotal));
        $iva = 0;
        foreach ($f->totalConImpuestos->totalImpuesto ?? [] as $imp) {
            if ((string) $imp->codigo === '2') { // 2 = IVA
                $iva += Dinero::centavos(trim((string) $imp->valor));
            }
        }

        // Subtotal + impuestos debe dar el total (otros impuestos como ICE se aceptan en el total)
        if ($total <= 0 || $subtotal <= 0 || $total < $subtotal + $iva - 1) {
            throw self::invalido('Los totales de la factura no cuadran.');
        }

        $detalle = $doc->detalles->detalle[0]->descripcion ?? null;

        return [
            'ruc' => $ruc,
            'razon_social' => trim((string) $t->razonSocial) ?: $ruc,
            'numero' => $numero,
            'clave_acceso' => $clave,
            'fecha_emision' => $fecha->format('Y-m-d'),
            'subtotal' => Dinero::texto($subtotal),
            'iva' => Dinero::texto($iva),
            'total' => Dinero::texto($total),
            'descripcion' => $detalle !== null ? mb_substr(trim((string) $detalle), 0, 200) : null,
        ];
    }

    /** IVA del 15 % sobre un subtotal, redondeado al centavo. */
    public static function ivaDe(int $subtotalCentavos, bool $conIva): int
    {
        return $conIva ? intdiv($subtotalCentavos * self::TASA_IVA * 2 + 100, 200) : 0;
    }

    /** 49 dígitos; el último es el verificador módulo 11 de los 48 anteriores. */
    public static function claveValida(string $clave): bool
    {
        if (preg_match('/^\d{49}$/', $clave) !== 1) {
            return false;
        }

        $suma = 0;
        $factor = 2;
        for ($i = 47; $i >= 0; $i--) {
            $suma += (int) $clave[$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        $digito = 11 - ($suma % 11);
        $digito = match ($digito) {
            11 => 0,
            10 => 1,
            default => $digito,
        };

        return $digito === (int) $clave[48];
    }

    private static function invalido(string $mensaje): ApiException
    {
        return new ApiException('XML_INVALIDO', $mensaje, 422);
    }
}
