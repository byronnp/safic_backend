<?php

namespace App\Modules\Asambleas\Services;

use App\Core\Privacy\DatosPersonales;
use App\Core\Storage\ArchivosCondominio;
use App\Models\User;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Finanzas\Actions\EstadoDeUnidadParaAreasAction;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Collection;

/**
 * Cómo se ve un poder en la API: con la unidad, quién lo otorgó y quién lo representa, el enlace temporal al documento y si la unidad
 * está en mora hoy. La cédula del representante sale enmascarada; el documento se compara con el PDF.
 */
final class PresentadorDePoder
{
    public function __construct(private readonly ArchivosCondominio $archivos, private readonly EstadoDeUnidadParaAreasAction $estado) {}

    /**
     * @param  Collection<int, AsambleaPoder>  $poderes
     * @return list<array<string, mixed>>
     */
    public function lista(Collection $poderes): array
    {
        $unidades = Unidad::query()->withTrashed()->whereIn('id', $poderes->pluck('unidad_id'))->get()->keyBy('id');
        $personas = Persona::query()->withTrashed()->whereIn('id', $poderes->pluck('otorgante_persona_id')->filter())->get()->keyBy('id');
        $usuarios = User::query()->whereIn('id', $poderes->pluck('cargado_por')->merge($poderes->pluck('validado_por'))->filter()->unique())->pluck('name', 'id');
        $alDia = $this->estado->alDia($poderes->pluck('unidad_id')->unique()->values()->all());

        return $poderes->map(function (AsambleaPoder $p) use ($unidades, $personas, $usuarios, $alDia): array {
            $otorgante = $p->otorgante_persona_id !== null ? $personas->get($p->otorgante_persona_id) : null;

            return [
                'id' => $p->id, 'asamblea_id' => $p->asamblea_id, 'unidad_id' => $p->unidad_id,
                'unidad_codigo' => (string) ($unidades[$p->unidad_id]->codigo ?? '—'),
                'alicuota' => (string) ($unidades[$p->unidad_id]->alicuota ?? '0'),
                'otorgante' => $otorgante?->nombreCompleto(),
                'tipo' => $p->tipo, 'estado' => $p->estado, 'motivo_rechazo' => $p->motivo_rechazo,
                'representante_nombre' => $p->representante_nombre,
                'representante_documento' => DatosPersonales::enmascararDocumento($p->representante_documento),
                'representante_registrado' => $p->representante_persona_id !== null,
                'documento_nombre' => $p->documento_nombre,
                'documento_url' => $this->archivos->existe($p->documento_ruta) ? $this->archivos->urlTemporal($p->documento_ruta) : null,
                'cargado_por' => $p->cargado_por !== null ? ($usuarios[$p->cargado_por] ?? null) : null,
                'cargado_en' => $p->created_at->toIso8601String(),
                'validado_por' => $p->validado_por !== null ? ($usuarios[$p->validado_por] ?? null) : null,
                'validado_en' => $p->validado_en?->toIso8601String(),
                'unidad_en_mora' => ! ($alDia[$p->unidad_id] ?? true),
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function uno(AsambleaPoder $poder): array
    {
        return $this->lista(collect([$poder]))[0];
    }
}
