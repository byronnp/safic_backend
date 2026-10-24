<?php

namespace App\Modules\Asambleas\Actions;

use App\Modules\Finanzas\Actions\EstadoDeUnidadParaAreasAction;
use App\Modules\Unidades\Actions\PadronDeAsambleaAction;

/**
 * Padrón de la asamblea con los datos de hoy: unidades con propietario, suma de sus alícuotas y cuántas están en mora (no votan).
 * Es una vista previa para quien prepara: la foto definitiva se congela al instalar la asamblea.
 */
final class VerPadronAction
{
    /** Escala de las alícuotas (4 decimales) para sumar sin errores de coma flotante. */
    private const ESCALA = 10000;

    public function __construct(private readonly PadronDeAsambleaAction $padron, private readonly EstadoDeUnidadParaAreasAction $estado) {}

    /**
     * @return array{unidades: int, suma_alicuotas: string, en_mora: array{unidades: int, alicuotas: string}, quorum_minimo_pct: string}
     */
    public function execute(): array
    {
        $unidades = $this->padron->execute();
        $alDia = $this->estado->alDia(array_column($unidades, 'unidad_id'));

        $total = 0;
        $mora = 0;
        $moras = 0;
        foreach ($unidades as $u) {
            $a = (int) round((float) $u['alicuota'] * self::ESCALA);
            $total += $a;
            if (! ($alDia[$u['unidad_id']] ?? true)) {
                $mora += $a;
                $moras++;
            }
        }

        return [
            'unidades' => count($unidades),
            'suma_alicuotas' => $this->texto($total),
            'en_mora' => ['unidades' => $moras, 'alicuotas' => $this->texto($mora)],
            // Primera convocatoria: más del 50 % de las alícuotas (Art. 39)
            'quorum_minimo_pct' => '50.00',
        ];
    }

    private function texto(int $escalado): string
    {
        return number_format($escalado / self::ESCALA, 2, '.', '');
    }
}
