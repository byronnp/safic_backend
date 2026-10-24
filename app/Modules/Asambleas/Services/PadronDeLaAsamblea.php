<?php

namespace App\Modules\Asambleas\Services;

use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaUnidad;
use App\Modules\Finanzas\Actions\EstadoDeUnidadParaAreasAction;
use App\Modules\Unidades\Actions\PadronDeAsambleaAction;

/**
 * El padrón de una asamblea: con los datos de hoy mientras no se instala, y con la foto congelada desde que se instala (alícuotas,
 * propietarios y mora de ese momento; un pago a mitad de la sesión no cambia nada).
 */
final class PadronDeLaAsamblea
{
    public function __construct(private readonly PadronDeAsambleaAction $padron, private readonly EstadoDeUnidadParaAreasAction $estado) {}

    /** @return list<array{unidad_id: int, codigo: string, alicuota: string, propietarios: list<array{persona_id: int, nombre: string}>, en_mora: bool}> */
    public function de(Asamblea $asamblea): array
    {
        if ($asamblea->instalada_en !== null) {
            return AsambleaUnidad::query()->where('asamblea_id', $asamblea->id)->get()
                ->sortBy('codigo', SORT_NATURAL)
                ->map(fn (AsambleaUnidad $u): array => [
                    'unidad_id' => $u->unidad_id, 'codigo' => $u->codigo, 'alicuota' => (string) $u->alicuota,
                    'propietarios' => $u->propietarios, 'en_mora' => $u->en_mora,
                ])->values()->all();
        }

        return $this->deHoy();
    }

    /** @return list<array{unidad_id: int, codigo: string, alicuota: string, propietarios: list<array{persona_id: int, nombre: string}>, en_mora: bool}> */
    public function deHoy(): array
    {
        $unidades = $this->padron->execute();
        $alDia = $this->estado->alDia(array_column($unidades, 'unidad_id'));

        return array_map(fn (array $u): array => $u + ['en_mora' => ! ($alDia[$u['unidad_id']] ?? true)], $unidades);
    }

    /** Guarda la foto: una fila por unidad del padrón de hoy. */
    public function congelar(Asamblea $asamblea): int
    {
        $ahora = now();
        $filas = array_map(fn (array $u): array => [
            'condominio_id' => $asamblea->condominio_id, 'asamblea_id' => $asamblea->id, 'unidad_id' => $u['unidad_id'], 'codigo' => $u['codigo'],
            'alicuota' => $u['alicuota'], 'en_mora' => $u['en_mora'], 'puede_votar' => ! $u['en_mora'],
            'propietario_persona_id' => $u['propietarios'][0]['persona_id'] ?? null,
            'propietarios' => json_encode($u['propietarios'], JSON_THROW_ON_ERROR), 'created_at' => $ahora, 'updated_at' => $ahora,
        ], $this->deHoy());
        foreach (array_chunk($filas, 500) as $lote) {
            AsambleaUnidad::query()->insert($lote);
        }

        return count($filas);
    }
}
