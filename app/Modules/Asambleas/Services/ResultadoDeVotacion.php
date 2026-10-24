<?php

namespace App\Modules\Asambleas\Services;

use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaAsistencia;
use App\Modules\Asambleas\Models\AsambleaUnidad;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Asambleas\Models\Voto;

/**
 * Cuenta una votación por alícuota (enteros × 10 000, sin coma flotante) y decide si el punto se aprueba:
 * - base «presentes»: las alícuotas de las unidades presentes con derecho a voto (sin mora), aunque no hayan votado (Art. 42);
 * - base «total»: todas las alícuotas del padrón congelado (reforma del reglamento, Art. 39).
 * Mayoría simple: más de la mitad de la base; calificada: al menos el porcentaje del punto. La abstención no suma al «sí».
 */
final class ResultadoDeVotacion
{
    private const ESCALA = 10000;

    /**
     * @return array{
     *     base_alicuota: string, alicuota_si: string, alicuota_no: string, alicuota_abstencion: string,
     *     votos_si: int, votos_no: int, votos_abstencion: int, resultado: string,
     *     presentes_con_voto: int, presentes_en_mora: int, votos_emitidos: int, pct_si: string, pct_no: string, pct_abstencion: string
     * }
     */
    public function calcular(Votacion $v): array
    {
        $asamblea = Asamblea::query()->findOrFail($v->asamblea_id);
        $foto = AsambleaUnidad::query()->where('asamblea_id', $asamblea->id)->get()->keyBy('unidad_id');
        $presentes = AsambleaAsistencia::query()->presentes()->where('asamblea_id', $asamblea->id)->pluck('unidad_id')->all();

        $conVoto = 0;
        $enMora = 0;
        $basePresentes = 0;
        foreach ($presentes as $unidadId) {
            $u = $foto->get($unidadId);
            if ($u === null) {
                continue;
            }
            if ($u->puede_votar) {
                $conVoto++;
                $basePresentes += $this->entero((string) $u->alicuota);
            } else {
                $enMora++;
            }
        }
        $base = $v->base === 'total' ? $foto->sum(fn (AsambleaUnidad $u): int => $this->entero((string) $u->alicuota)) : $basePresentes;

        $suma = ['si' => 0, 'no' => 0, 'abstencion' => 0];
        $cuenta = ['si' => 0, 'no' => 0, 'abstencion' => 0];
        foreach (Voto::query()->where('votacion_id', $v->id)->get() as $voto) {
            $suma[$voto->opcion] += $this->entero((string) $voto->alicuota);
            $cuenta[$voto->opcion]++;
        }

        $aprobado = $base > 0 && ($v->mayoria === 'calificada'
            ? $this->alcanza($suma['si'], $base, (string) $v->umbral_pct)
            : $base < $suma['si'] * 2);

        return [
            'base_alicuota' => $this->texto($base), 'alicuota_si' => $this->texto($suma['si']), 'alicuota_no' => $this->texto($suma['no']),
            'alicuota_abstencion' => $this->texto($suma['abstencion']),
            'votos_si' => $cuenta['si'], 'votos_no' => $cuenta['no'], 'votos_abstencion' => $cuenta['abstencion'],
            'resultado' => $aprobado ? Votacion::APROBADO : Votacion::RECHAZADO,
            'presentes_con_voto' => $conVoto, 'presentes_en_mora' => $enMora, 'votos_emitidos' => array_sum($cuenta),
            'pct_si' => $this->porcentaje($suma['si'], $base), 'pct_no' => $this->porcentaje($suma['no'], $base),
            'pct_abstencion' => $this->porcentaje($suma['abstencion'], $base),
        ];
    }

    /** ¿El «sí» llega al porcentaje pedido sobre la base? Todo en enteros: sí / base ≥ umbral / 100. */
    private function alcanza(int $si, int $base, string $umbralPct): bool
    {
        // umbral con dos decimales → centésimas de punto porcentual
        $umbral = (int) round((float) $umbralPct * 100);

        return $si * 10000 >= $umbral * $base;
    }

    private function entero(string $alicuota): int
    {
        return (int) round((float) $alicuota * self::ESCALA);
    }

    private function texto(int $escalado): string
    {
        return number_format($escalado / self::ESCALA, 4, '.', '');
    }

    private function porcentaje(int $parte, int $base): string
    {
        return $base === 0 ? '0.00' : number_format($parte * 100 / $base, 2, '.', '');
    }
}
