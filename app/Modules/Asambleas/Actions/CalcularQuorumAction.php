<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Tenancy\Calendario;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaAsistencia;
use App\Modules\Asambleas\Services\PadronDeLaAsamblea;
use Carbon\CarbonImmutable;

/**
 * Quórum en vivo por suma de alícuotas (Art. 39): primera convocatoria con más del 50 %; segunda, 60 minutos después, con los presentes.
 * Cuenta a quien sigue en la sala (sin salida y, si es virtual, con actividad en los últimos 10 minutos). Por defecto la unidad en mora
 * suma al quórum aunque no vote; si el condominio lo cambia, sale de la base y de los presentes.
 *
 * Los cálculos usan enteros (alícuotas × 10 000) para no sumar con coma flotante.
 */
final class CalcularQuorumAction
{
    private const ESCALA = 10000;

    public function __construct(
        private readonly PadronDeLaAsamblea $padron,
        private readonly ObtenerConfiguracionAction $configuracion,
        private readonly Calendario $calendario,
    ) {}

    /**
     * @return array{
     *     registro_abierto: bool, unidades_total: int, unidades_presentes: int, alicuota_total: string, alicuota_presente: string,
     *     porcentaje: string, minimo_pct: string, hay_quorum: bool, mora_cuenta_para_quorum: bool,
     *     por_forma: array{presencial: int, virtual: int, poder: int},
     *     segunda_convocatoria: array{desde: string, abierta: bool, minutos_faltan: int}
     * }
     */
    public function execute(Asamblea $asamblea): array
    {
        $unidades = $this->padron->de($asamblea);
        // Instalada, rige la regla y la mora de ese momento (la foto); antes, las de hoy
        $moraCuenta = $asamblea->mora_cuenta_para_quorum ?? $this->configuracion->modelo()->mora_cuenta_para_quorum;
        $presentes = AsambleaAsistencia::query()->presentes()->where('asamblea_id', $asamblea->id)->get()->keyBy('unidad_id');

        $total = 0;
        $presente = 0;
        $cuentan = 0;
        $presentesCuentan = 0;
        $forma = ['presencial' => 0, 'virtual' => 0, 'poder' => 0];
        foreach ($unidades as $u) {
            if ($u['en_mora'] && ! $moraCuenta) {
                continue;
            }
            $a = (int) round((float) $u['alicuota'] * self::ESCALA);
            $total += $a;
            $cuentan++;
            if ($presentes->has($u['unidad_id'])) {
                $presente += $a;
                $presentesCuentan++;
                $forma[$presentes[$u['unidad_id']]->forma]++;
            }
        }

        $desde = CarbonImmutable::instance($asamblea->fecha_hora)->addMinutes(Asamblea::MINUTOS_SEGUNDA_CONVOCATORIA);

        return [
            'registro_abierto' => $asamblea->registroAbierto(),
            'unidades_total' => $cuentan, 'unidades_presentes' => $presentesCuentan,
            'alicuota_total' => $this->texto($total), 'alicuota_presente' => $this->texto($presente),
            'porcentaje' => $total === 0 ? '0.00' : number_format($presente * 100 / $total, 2, '.', ''),
            'minimo_pct' => '50.00',
            // Más de la mitad, sin redondeos: el 50 % exacto no alcanza
            'hay_quorum' => $total > 0 && $presente * 2 > $total,
            'mora_cuenta_para_quorum' => $moraCuenta,
            'por_forma' => $forma,
            'segunda_convocatoria' => [
                'desde' => $desde->setTimezone($this->calendario->zonaHoraria())->format('Y-m-d\TH:i'),
                'abierta' => now()->greaterThanOrEqualTo($desde),
                'minutos_faltan' => max(0, (int) ceil(now()->diffInSeconds($desde, false) / 60)),
            ],
        ];
    }

    private function texto(int $escalado): string
    {
        return number_format($escalado / self::ESCALA, 2, '.', '');
    }
}
