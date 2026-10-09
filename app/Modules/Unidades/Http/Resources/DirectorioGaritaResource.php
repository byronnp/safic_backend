<?php

namespace App\Modules\Unidades\Http\Resources;

use App\Core\Validation\Rules\PlacaEc;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Unidad;
use App\Modules\Unidades\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lo único que ve el guardia: nombre, unidad, teléfono y placas. Es una lista
 * cerrada a propósito: no hay cédula, correo ni identificadores de persona.
 * El teléfono sale completo (para llamar) porque el permiso garita.directorio
 * existe para eso; el resto de pantallas siguen enmascarándolo.
 *
 * @mixin Unidad
 */
class DirectorioGaritaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $placa = PlacaEc::normalizar((string) $request->query('buscar')) ?? strtoupper((string) preg_replace('/[\s\-]/', '', (string) $request->query('buscar')));

        return [
            'unidad' => [
                'id' => $this->id,
                'codigo' => $this->codigo,
                'tipo' => $this->tipo,
                'bloque' => $this->bloque?->nombre,
            ],
            'ocupantes' => $this->ocupantesVigentes
                ->sortByDesc('es_principal')
                ->map(fn (Ocupante $o) => [
                    'nombre' => $o->persona->nombreCompleto(),
                    'relacion' => $o->relacion,
                    'telefono' => $o->persona->telefono,
                ])
                ->values()
                ->all(),
            'vehiculos' => $this->vehiculos
                ->map(fn (Vehiculo $v) => [
                    'placa' => $v->placa,
                    'descripcion' => $this->describir($v),
                    // La placa buscada, para resaltarla en la lista
                    'coincide' => $placa !== '' && str_contains(str_replace('-', '', $v->placa), str_replace('-', '', $placa)),
                ])
                ->values()
                ->all(),
        ];
    }

    private function describir(Vehiculo $vehiculo): string
    {
        $modelo = trim(($vehiculo->marca ?? '').' '.($vehiculo->modelo ?? ''));

        return implode(' · ', array_filter([$modelo, $vehiculo->color]));
    }
}
