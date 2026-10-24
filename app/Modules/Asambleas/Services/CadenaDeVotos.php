<?php

namespace App\Modules\Asambleas\Services;

use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Asambleas\Models\Voto;
use Carbon\CarbonImmutable;

/**
 * Hash encadenado de los votos de una votación: hash = sha256(hash del voto anterior + datos del voto). El primero parte de una semilla
 * propia de la votación. Cambiar, quitar o reordenar un voto rompe la cadena de ahí en adelante.
 */
final class CadenaDeVotos
{
    public function semilla(int $votacionId): string
    {
        return hash('sha256', "votacion:{$votacionId}");
    }

    /** @param array{unidad_id: int, opcion: string, alicuota: string, canal: string, emitido_por: int|null, emitido_en: string} $d */
    public function hash(string $anterior, array $d): string
    {
        return hash('sha256', implode('|', [$anterior, $d['unidad_id'], $d['opcion'], $d['alicuota'], $d['canal'], $d['emitido_por'] ?? '', $d['emitido_en']]));
    }

    /** El instante del voto como se firma: UTC, a la segundo. */
    public function instante(\DateTimeInterface $momento): string
    {
        return CarbonImmutable::instance($momento)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** @return array{valida: bool, votos: int, ultimo_hash: string|null, primer_voto_alterado: int|null} */
    public function verificar(Votacion $votacion): array
    {
        $anterior = $this->semilla($votacion->id);
        $n = 0;
        foreach (Voto::query()->where('votacion_id', $votacion->id)->orderBy('id')->get() as $v) {
            $esperado = $this->hash($anterior, [
                'unidad_id' => $v->unidad_id, 'opcion' => $v->opcion, 'alicuota' => (string) $v->alicuota, 'canal' => $v->canal,
                'emitido_por' => $v->emitido_por, 'emitido_en' => $this->instante($v->emitido_en),
            ]);
            if (! hash_equals($esperado, $v->hash)) {
                return ['valida' => false, 'votos' => $n, 'ultimo_hash' => $anterior, 'primer_voto_alterado' => $v->id];
            }
            $anterior = $v->hash;
            $n++;
        }

        return ['valida' => true, 'votos' => $n, 'ultimo_hash' => $n === 0 ? null : $anterior, 'primer_voto_alterado' => null];
    }
}
