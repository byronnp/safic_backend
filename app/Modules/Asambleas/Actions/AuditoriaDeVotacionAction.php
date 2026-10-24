<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Access\Catalogo;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\AsambleaUnidad;
use App\Modules\Asambleas\Models\Candidato;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Asambleas\Models\Voto;
use App\Modules\Asambleas\Services\CadenaDeVotos;
use App\Modules\Asambleas\Services\ResultadoDeVotacion;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Actions\ResumenPersonasAction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reporte descargable de una votación cerrada: unidad, alícuota, opción y hora de cada voto, anexable al acta. Con voto secreto se
 * publica solo el total por opción y la lista de unidades que votaron (sin la opción de cada una). Incluye la comprobación de la cadena de hashes.
 */
final class AuditoriaDeVotacionAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Calendario $calendario,
        private readonly CadenaDeVotos $cadena,
        private readonly ResultadoDeVotacion $resultado,
        private readonly ResumenPersonasAction $personas,
    ) {}

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
        $nombres = ['si' => 'Sí', 'no' => 'No', 'abstencion' => 'Abstención', 'blanco' => 'En blanco'];
        // En una elección, cada candidato es una opción
        $candidatos = Candidato::query()->where('punto_id', $v->punto_id)->where('cargo', (string) $v->cargo)->get()->keyBy('id');
        $personas = $this->personas->execute($candidatos->pluck('persona_id')->unique()->all());
        $nombreDe = fn (Voto $x): string => $x->opcion === 'candidato'
            ? ($personas[$candidatos[$x->candidato_id]->persona_id ?? 0]['nombre'] ?? 'Candidato')
            : $nombres[$x->opcion];

        $votos = Voto::query()->where('votacion_id', $v->id)->orderBy('id')->get()->map(fn (Voto $x): array => [
            'codigo' => (string) ($codigos[$x->unidad_id] ?? '—'), 'alicuota' => (string) $x->alicuota, 'opcion' => $nombreDe($x), 'canal' => $x->canal === 'mesa' ? 'Mesa' : 'App',
            'hora' => $x->emitido_en->setTimezone($zona)->format('d/m/Y H:i:s'),
        ])->all();
        if ($v->secreta) {
            // Con voto secreto no se publica qué votó cada unidad: se ordena por código para no delatar el orden de llegada
            usort($votos, fn (array $a, array $b): int => strnatcmp($a['codigo'], $b['codigo']));
        }

        $pdf = Pdf::loadView('asambleas.auditoria', [
            'condominio' => ['nombre' => $condominio->nombre, 'ruc' => $condominio->ruc],
            'asamblea' => ['titulo' => $asamblea->titulo, 'fecha' => $asamblea->fecha_hora->setTimezone($zona)->format('d/m/Y H:i')],
            'punto' => ['orden' => $punto->orden, 'titulo' => $punto->titulo.($v->esEleccion() ? ' · '.app(Catalogo::class)->etiquetaDeRol($v->cargo) : '')],
            'votacion' => [
                'abierta' => $v->abierta_en->setTimezone($zona)->format('d/m/Y H:i'), 'cerrada' => $v->cerrada_en?->setTimezone($zona)->format('d/m/Y H:i'),
                'mayoria' => $v->mayoria === 'calificada' ? "Mayoría calificada ({$v->umbral_pct} %)" : 'Mayoría simple', 'base' => $v->base === 'total' ? 'total de alícuotas' : 'alícuotas presentes con derecho a voto',
                'base_alicuota' => (string) $v->base_alicuota, 'secreta' => $v->secreta, 'resultado' => $v->resultado === Votacion::APROBADO ? 'APROBADO' : 'RECHAZADO',
            ],
            'totales' => $this->totales($v, $candidatos, $personas),
            'votos' => $votos, 'integridad' => $this->cadena->verificar($v), 'emitida' => now($zona)->format('d/m/Y H:i'),
        ]);

        return ['contenido' => $pdf->output(), 'nombre' => 'auditoria-votacion-punto-'.$punto->orden.'-'.Str::slug($punto->titulo).'.pdf'];
    }

    /**
     * Total por opción: Sí / No / Abstención, o cada candidato y el voto en blanco.
     *
     * @param  Collection<int, Candidato>  $candidatos
     * @param  array<int, array{nombre: string, unidad: string|null, propietario: bool}>  $personas
     * @return list<array{etiqueta: string, votos: int|null, alicuota: string}>
     */
    private function totales(Votacion $v, $candidatos, array $personas): array
    {
        if (! $v->esEleccion()) {
            return [
                ['etiqueta' => 'Sí', 'votos' => $v->votos_si, 'alicuota' => (string) $v->alicuota_si],
                ['etiqueta' => 'No', 'votos' => $v->votos_no, 'alicuota' => (string) $v->alicuota_no],
                ['etiqueta' => 'Abstención', 'votos' => $v->votos_abstencion, 'alicuota' => (string) $v->alicuota_abstencion],
            ];
        }
        $e = $this->resultado->calcularEleccion($v);
        $filas = array_map(fn (array $c): array => [
            'etiqueta' => ($personas[$candidatos[$c['candidato_id']]->persona_id ?? 0]['nombre'] ?? 'Candidato').($v->ganador_candidato_id === $c['candidato_id'] ? ' (ganador)' : ''),
            'votos' => $c['votos'], 'alicuota' => $c['alicuota'],
        ], $e['candidatos']);
        $filas[] = ['etiqueta' => 'En blanco', 'votos' => $e['blanco']['votos'], 'alicuota' => $e['blanco']['alicuota']];

        return $filas;
    }
}
