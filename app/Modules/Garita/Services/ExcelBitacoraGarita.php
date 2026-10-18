<?php

namespace App\Modules\Garita\Services;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Arma el .xlsx de la bitácora de garita a partir de las filas que devuelve BitacoraGaritaAction. */
final class ExcelBitacoraGarita
{
    private const TIPOS = ['visita' => 'Visita', 'proveedor' => 'Proveedor', 'delivery' => 'Delivery'];

    private const AUTORIZADO = ['qr' => 'QR', 'llamada' => 'Llamada', 'app_residente' => 'App del residente', 'administracion' => 'Administración'];

    /** @param  list<array<string, mixed>>  $accesos */
    public function generar(string $rutaDestino, array $accesos): void
    {
        $writer = new Writer;
        $writer->openToFile($rutaDestino);
        $writer->getCurrentSheet()->setName('Bitácora');
        $writer->addRow(Row::fromValues(['Nombre', 'Tipo', 'Unidad', 'Destino', 'Autorizado por', 'Entrada', 'Salida', 'Estado', 'Sin conexión', 'Nota', 'Registrado por']));

        foreach ($accesos as $a) {
            $writer->addRow(Row::fromValues([
                self::texto($a['nombre']), self::TIPOS[$a['tipo']] ?? $a['tipo'], self::texto($a['unidad']), self::texto($a['destino']),
                self::AUTORIZADO[$a['autorizado_por']] ?? $a['autorizado_por'],
                self::hora($a['entrada_en']), self::hora($a['salida_en']),
                $a['estado'] === 'dentro' ? 'Dentro' : 'Salió', $a['sin_conexion'] ? 'Sí' : 'No', self::texto($a['nota']), self::texto($a['registrado_por']),
            ]));
        }

        $writer->close();
    }

    /** «2026-10-17 15:00» a partir del ISO con zona del condominio. */
    private static function hora(?string $iso): string
    {
        return $iso === null ? '' : str_replace('T', ' ', substr($iso, 0, 16));
    }

    /** Un texto que empieza con = + - @ lo ejecutaría Excel como fórmula: se antepone una comilla. */
    private static function texto(mixed $valor): string
    {
        $t = (string) ($valor ?? '');

        return $t !== '' && str_contains('=+-@'."\t\r", $t[0]) ? "'".$t : $t;
    }
}
