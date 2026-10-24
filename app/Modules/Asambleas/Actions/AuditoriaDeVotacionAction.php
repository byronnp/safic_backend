<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\AsambleaUnidad;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Asambleas\Models\Voto;
use App\Modules\Asambleas\Services\CadenaDeVotos;
use App\Modules\Plataforma\Models\Condominio;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * Reporte descargable de una votación cerrada: unidad, alícuota, opción y hora de cada voto, anexable al acta. Con voto secreto se
 * publica solo el total por opción y la lista de unidades que votaron (sin la opción de cada una). Incluye la comprobación de la cadena de hashes.
 */
final class AuditoriaDeVotacionAction
{
    public function __construct(private readonly TenantContext $tenant, private readonly Calendario $calendario, private readonly CadenaDeVotos $cadena) {}

    /** @return array{contenido: string, nombre: string} */
    public function execute(int $votacionId): array
    {
        $v = Votacion::query()->findOrFail($votacionId);
        if ($v->estaAbierta()) {
            throw new ApiException('VOTACION_ABIERTA', 'La auditoría se emite al cerrar la votación.', 409);
        }
        $asamblea = Asamblea::query()->findOrFail($v->asamblea_id);
        $punto = AsambleaPunto::query()->findOrFail($v->punto_id);
        $condominio = Condominio::query()->findOrFail($this->tenant->require());
        $zona = $this->calendario->zonaHoraria();
        $codigos = AsambleaUnidad::query()->where('asamblea_id', $asamblea->id)->pluck('codigo', 'unidad_id');
        $nombres = ['si' => 'Sí', 'no' => 'No', 'abstencion' => 'Abstención'];

        $votos = Voto::query()->where('votacion_id', $v->id)->orderBy('id')->get()->map(fn (Voto $x): array => [
            'codigo' => (string) ($codigos[$x->unidad_id] ?? '—'), 'alicuota' => (string) $x->alicuota, 'opcion' => $nombres[$x->opcion], 'canal' => $x->canal === 'mesa' ? 'Mesa' : 'App',
            'hora' => $x->emitido_en->setTimezone($zona)->format('d/m/Y H:i:s'),
        ])->all();
        if ($v->secreta) {
            // Con voto secreto no se publica qué votó cada unidad: se ordena por código para no delatar el orden de llegada
            usort($votos, fn (array $a, array $b): int => strnatcmp($a['codigo'], $b['codigo']));
        }

        $pdf = Pdf::loadView('asambleas.auditoria', [
            'condominio' => ['nombre' => $condominio->nombre, 'ruc' => $condominio->ruc],
            'asamblea' => ['titulo' => $asamblea->titulo, 'fecha' => $asamblea->fecha_hora->setTimezone($zona)->format('d/m/Y H:i')],
            'punto' => ['orden' => $punto->orden, 'titulo' => $punto->titulo],
            'votacion' => [
                'abierta' => $v->abierta_en->setTimezone($zona)->format('d/m/Y H:i'), 'cerrada' => $v->cerrada_en?->setTimezone($zona)->format('d/m/Y H:i'),
                'mayoria' => $v->mayoria === 'calificada' ? "Mayoría calificada ({$v->umbral_pct} %)" : 'Mayoría simple', 'base' => $v->base === 'total' ? 'total de alícuotas' : 'alícuotas presentes con derecho a voto',
                'base_alicuota' => (string) $v->base_alicuota, 'secreta' => $v->secreta, 'resultado' => $v->resultado === Votacion::APROBADO ? 'APROBADO' : 'RECHAZADO',
            ],
            'totales' => [
                'votos_si' => $v->votos_si, 'votos_no' => $v->votos_no, 'votos_abstencion' => $v->votos_abstencion,
                'alicuota_si' => (string) $v->alicuota_si, 'alicuota_no' => (string) $v->alicuota_no, 'alicuota_abstencion' => (string) $v->alicuota_abstencion,
            ],
            'votos' => $votos, 'integridad' => $this->cadena->verificar($v), 'emitida' => now($zona)->format('d/m/Y H:i'),
        ]);

        return ['contenido' => $pdf->output(), 'nombre' => 'auditoria-votacion-punto-'.$punto->orden.'-'.Str::slug($punto->titulo).'.pdf'];
    }
}
