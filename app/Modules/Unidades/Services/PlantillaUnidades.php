<?php

namespace App\Modules\Unidades\Services;

use App\Modules\Unidades\Models\Unidad;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Genera el .xlsx de ejemplo: hoja "Unidades" con solo los títulos (lo que se
 * importa) y hoja "Instrucciones". Una fila de ejemplo se importaría por error.
 */
final class PlantillaUnidades
{
    public function generar(string $rutaDestino, string $metodoCobro): void
    {
        $writer = new Writer;
        $writer->openToFile($rutaDestino);

        $writer->getCurrentSheet()->setName('Unidades');
        $writer->addRow(Row::fromValues(ColumnasImportacionUnidades::para($metodoCobro)));

        $writer->addNewSheetAndMakeItCurrent()->setName('Instrucciones');
        foreach ($this->instrucciones($metodoCobro) as $linea) {
            $writer->addRow(Row::fromValues($linea));
        }

        $writer->close();
    }

    /**
     * @return list<list<string>>
     */
    private function instrucciones(string $metodoCobro): array
    {
        $obligatorias = implode(', ', ColumnasImportacionUnidades::obligatorias($metodoCobro));

        return [
            ['Cómo llenar la hoja "Unidades"'],
            ['Una unidad por fila, a partir de la fila 2. Máximo '.LectorExcelUnidades::MAX_FILAS.' filas.'],
            ['Obligatorias: '.$obligatorias.'.'],
            ['codigo', 'Ej. A-102. Letras, números, espacios, guion, punto o barra. Máximo 20 caracteres. No se repite.'],
            ['bloque', 'Nombre del bloque. Si no existe, se crea.'],
            ['tipo', implode(', ', Unidad::TIPOS)],
            ['piso', 'Número entero entre -5 y 200.'],
            ['area_m2', 'Área en m², mayor que 0, hasta 2 decimales.'],
            ['alicuota', 'Porcentaje mayor que 0 y hasta 100, hasta 4 decimales.'],
            ['responsable_pago', implode(' o ', Unidad::RESPONSABLES_PAGO).' (por defecto, propietario).'],
            ['Antes de guardar se muestra una vista previa con los errores por fila; no se crea nada hasta que confirmes.'],
        ];
    }
}
