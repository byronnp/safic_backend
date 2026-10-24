<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: agregar o editar un punto del orden del día de un borrador. El tipo decide cómo se decide el punto:
 * - informativo: solo se trata, no se vota;
 * - votación y elección: mayoría simple (más de la mitad de los votos de los concurrentes, Art. 42) o calificada con su porcentaje;
 * - reforma del reglamento: siempre calificada, sobre el total de alícuotas (Art. 39). El porcentaje no se inventa: sale de las
 *   reglas de asamblea del condominio o se indica en el punto, hasta confirmarlo con asesoría legal.
 */
final class GuardarPuntoAction
{
    /** Puntos que caben en un orden del día. */
    public const MAXIMO = 40;

    public function __construct(private readonly ObtenerConfiguracionAction $configuracion) {}

    /** @param array{titulo: string, descripcion?: string|null, tipo: string, mayoria?: string|null, umbral_pct?: float|string|null, base?: string|null} $d */
    public function crear(int $asambleaId, array $d): AsambleaPunto
    {
        return DB::transaction(function () use ($asambleaId, $d): AsambleaPunto {
            $asamblea = $this->borrador($asambleaId);
            $cantidad = AsambleaPunto::query()->where('asamblea_id', $asamblea->id)->count();
            if ($cantidad >= self::MAXIMO) {
                throw new ApiException('DEMASIADOS_PUNTOS', 'El orden del día admite hasta '.self::MAXIMO.' puntos.', 422);
            }

            return AsambleaPunto::create($this->valores($d) + ['asamblea_id' => $asamblea->id, 'orden' => $cantidad + 1]);
        });
    }

    /** @param array{titulo: string, descripcion?: string|null, tipo: string, mayoria?: string|null, umbral_pct?: float|string|null, base?: string|null} $d */
    public function actualizar(int $asambleaId, int $puntoId, array $d): AsambleaPunto
    {
        return DB::transaction(function () use ($asambleaId, $puntoId, $d): AsambleaPunto {
            $asamblea = $this->borrador($asambleaId);
            $punto = AsambleaPunto::query()->where('asamblea_id', $asamblea->id)->lockForUpdate()->findOrFail($puntoId);
            $punto->forceFill($this->valores($d))->save();

            return $punto;
        });
    }

    private function borrador(int $asambleaId): Asamblea
    {
        $asamblea = Asamblea::query()->lockForUpdate()->findOrFail($asambleaId);
        if (! $asamblea->esBorrador()) {
            throw new ApiException('ASAMBLEA_NO_EDITABLE', 'Solo se edita el orden del día de una asamblea en borrador: esta ya se convocó.', 409);
        }

        return $asamblea;
    }

    /**
     * @param  array{titulo: string, descripcion?: string|null, tipo: string, mayoria?: string|null, umbral_pct?: float|string|null, base?: string|null}  $d
     * @return array<string, mixed>
     */
    private function valores(array $d): array
    {
        $descripcion = isset($d['descripcion']) ? trim($d['descripcion']) : '';
        $base = ['titulo' => trim($d['titulo']), 'descripcion' => $descripcion === '' ? null : $descripcion, 'tipo' => $d['tipo']];

        if ($d['tipo'] === AsambleaPunto::INFORMATIVO) {
            return $base + ['mayoria' => 'ninguna', 'umbral_pct' => null, 'base' => null];
        }

        $mayoria = $d['tipo'] === AsambleaPunto::REFORMA ? 'calificada' : ($d['mayoria'] ?? 'simple');
        if ($mayoria === 'simple') {
            return $base + ['mayoria' => 'simple', 'umbral_pct' => null, 'base' => 'presentes'];
        }

        // Calificada: el porcentaje que debe alcanzar el «sí» sobre el total de alícuotas (o sobre los presentes si se indica)
        $umbral = $d['umbral_pct'] ?? $this->configuracion->execute()['porcentaje_reforma'];
        if ($umbral === null || (float) $umbral <= 50 || (float) $umbral > 100) {
            throw new ApiException('UMBRAL_REQUERIDO', $d['tipo'] === AsambleaPunto::REFORMA
                ? 'Indica el porcentaje legal para reformar el reglamento (mayor que 50 y hasta 100). Confírmalo con asesoría legal o guárdalo en las reglas de asamblea.'
                : 'Una mayoría calificada necesita su porcentaje (mayor que 50 y hasta 100).', 422);
        }

        return $base + [
            'mayoria' => 'calificada', 'umbral_pct' => number_format((float) $umbral, 2, '.', ''),
            'base' => $d['tipo'] === AsambleaPunto::REFORMA ? 'total' : ($d['base'] ?? 'total'),
        ];
    }
}
