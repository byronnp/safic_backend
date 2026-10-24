<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Access\Catalogo;
use App\Core\Tenancy\Calendario;
use App\Modules\Asambleas\Models\AsambleaAsistencia;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\AsambleaUnidad;
use App\Modules\Asambleas\Models\Candidato;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Asambleas\Models\Voto;
use App\Modules\Asambleas\Services\CadenaDeVotos;
use App\Modules\Asambleas\Services\ResultadoDeVotacion;
use App\Modules\Unidades\Actions\ResumenPersonasAction;

/**
 * Una votación vista desde la mesa: el conteo en vivo (solo la mesa lo ve mientras está abierta, para no influir en quienes aún no votan),
 * quién falta por votar y, ya cerrada, el resultado y la comprobación de la cadena de hashes. En una elección, el conteo es por candidato.
 */
final class VerVotacionAction
{
    public function __construct(
        private readonly ResultadoDeVotacion $resultado,
        private readonly CadenaDeVotos $cadena,
        private readonly Calendario $calendario,
        private readonly ResumenPersonasAction $personas,
    ) {}

    /** @return array<string, mixed> */
    public function execute(Votacion $v): array
    {
        $punto = AsambleaPunto::query()->findOrFail($v->punto_id);
        $zona = $this->calendario->zonaHoraria();
        $cerrada = ! $v->estaAbierta();
        $eleccion = $v->esEleccion();
        $r = $eleccion ? $this->resultado->calcularEleccion($v) : $this->resultado->calcular($v);

        return [
            'id' => $v->id, 'punto_id' => $punto->id, 'orden' => $punto->orden, 'titulo' => $punto->titulo, 'estado' => $v->estado,
            'secreta' => $v->secreta, 'mayoria' => $v->mayoria, 'umbral_pct' => $v->umbral_pct === null ? null : (string) $v->umbral_pct, 'base' => $v->base,
            'cargo' => $v->cargo, 'cargo_etiqueta' => $eleccion ? app(Catalogo::class)->etiquetaDeRol($v->cargo) : null,
            'abierta_en' => $v->abierta_en->setTimezone($zona)->format('Y-m-d\TH:i'),
            'cerrada_en' => $v->cerrada_en?->setTimezone($zona)->format('Y-m-d\TH:i'),
            // Cerrada, rige lo que se guardó al cerrar; abierta, el conteo provisional
            'resultado' => $cerrada ? $v->resultado : null,
            'cifras' => $eleccion ? null : $this->cifras($v, $r, $cerrada),
            'eleccion' => $eleccion ? $this->eleccion($v, $r, $cerrada) : null,
            'presentes_con_voto' => $r['presentes_con_voto'], 'presentes_en_mora' => $r['presentes_en_mora'], 'votos_emitidos' => $r['votos_emitidos'],
            'pendientes' => $cerrada ? [] : $this->pendientes($v),
            'integridad' => $cerrada ? $this->cadena->verificar($v) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function cifras(Votacion $v, array $r, bool $cerrada): array
    {
        $pct = array_intersect_key($r, array_flip(['pct_si', 'pct_no', 'pct_abstencion']));
        if ($cerrada) {
            return [
                'base_alicuota' => (string) $v->base_alicuota, 'alicuota_si' => (string) $v->alicuota_si, 'alicuota_no' => (string) $v->alicuota_no,
                'alicuota_abstencion' => (string) $v->alicuota_abstencion, 'votos_si' => $v->votos_si, 'votos_no' => $v->votos_no, 'votos_abstencion' => $v->votos_abstencion,
            ] + $pct;
        }

        return array_intersect_key($r, array_flip(['base_alicuota', 'alicuota_si', 'alicuota_no', 'alicuota_abstencion', 'votos_si', 'votos_no', 'votos_abstencion'])) + $pct;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function eleccion(Votacion $v, array $r, bool $cerrada): array
    {
        $candidatos = Candidato::query()->where('punto_id', $v->punto_id)->where('cargo', $v->cargo)->get()->keyBy('id');
        $nombres = $this->personas->execute($candidatos->pluck('persona_id')->unique()->all());

        return [
            'base_alicuota' => $cerrada ? (string) $v->base_alicuota : $r['base_alicuota'],
            'candidatos' => array_map(fn (array $c): array => [
                'candidato_id' => $c['candidato_id'], 'persona_id' => $candidatos[$c['candidato_id']]->persona_id ?? null,
                'nombre' => $nombres[$candidatos[$c['candidato_id']]->persona_id ?? 0]['nombre'] ?? 'Persona dada de baja',
                'unidad' => $nombres[$candidatos[$c['candidato_id']]->persona_id ?? 0]['unidad'] ?? null,
                'alicuota' => $c['alicuota'], 'votos' => $c['votos'], 'pct' => $c['pct'],
            ], $r['candidatos']),
            'blanco' => $r['blanco'],
            'ganador_candidato_id' => $cerrada ? $v->ganador_candidato_id : null,
        ];
    }

    /** @return list<array{unidad_id: int, codigo: string, asistente: string}> Unidades presentes con derecho a voto que aún no votaron */
    private function pendientes(Votacion $v): array
    {
        $votaron = Voto::query()->where('votacion_id', $v->id)->pluck('unidad_id')->all();
        $foto = AsambleaUnidad::query()->where('asamblea_id', $v->asamblea_id)->where('puede_votar', true)->get()->keyBy('unidad_id');
        $presentes = AsambleaAsistencia::query()->presentes()->where('asamblea_id', $v->asamblea_id)->whereIn('unidad_id', $foto->keys())->whereNotIn('unidad_id', $votaron)->get();

        return $presentes->map(fn (AsambleaAsistencia $a): array => ['unidad_id' => $a->unidad_id, 'codigo' => $foto[$a->unidad_id]->codigo, 'asistente' => $a->asistente_nombre])
            ->sortBy('codigo', SORT_NATURAL)->values()->all();
    }
}
