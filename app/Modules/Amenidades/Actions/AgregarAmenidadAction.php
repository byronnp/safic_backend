<?php

namespace App\Modules\Amenidades\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\TenantContext;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: agregar amenidades al condominio, del catálogo o propias.
 *
 * Una amenidad reservable con varias unidades se crea como registros separados
 * ("Área BBQ 1", "Área BBQ 2") para reservarlos por separado; una que no se reserva
 * es un solo registro con su cantidad ("Ascensores (3)"). Al agregar otra del mismo
 * tipo del catálogo la numeración sigue donde iba.
 */
final class AgregarAmenidadAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array{origen: string, amenidad_catalogo_id?: int|null, nombre?: string|null, categoria?: string|null, reservable?: bool, cantidad: int, ubicacion?: string|null}  $datos
     * @return list<int> ids de las amenidades creadas
     */
    public function execute(array $datos): array
    {
        $this->tenant->require();

        return DB::transaction(function () use ($datos): array {
            $catalogo = $datos['origen'] === 'catalogo'
                ? AmenidadCatalogo::query()->whereKey($datos['amenidad_catalogo_id'])->where('activa', true)->firstOrFail()
                : null;

            $base = $catalogo->nombre ?? trim((string) $datos['nombre']);
            $reservable = $catalogo->reservable ?? (bool) ($datos['reservable'] ?? false);
            $cantidad = $datos['cantidad'];

            $existentes = $catalogo === null
                ? 0
                : CondominioAmenidad::query()->where('amenidad_catalogo_id', $catalogo->id)->count();

            $registros = $this->registros($base, $reservable, $cantidad, $existentes, $catalogo !== null);

            $nombres = array_column($registros, 'nombre');
            if (CondominioAmenidad::query()->whereIn('nombre', $nombres)->exists()) {
                throw new ApiException('AMENIDAD_EXISTE', 'Ya hay una amenidad con ese nombre en el condominio: '.implode(', ', $nombres).'.', 422);
            }

            $ids = [];
            foreach ($registros as $registro) {
                $ids[] = CondominioAmenidad::create([
                    'amenidad_catalogo_id' => $catalogo?->id,
                    'nombre' => $registro['nombre'],
                    'categoria' => $catalogo->categoria ?? $datos['categoria'],
                    'cantidad' => $registro['cantidad'],
                    'ubicacion' => $datos['ubicacion'] ?? null,
                    'reservable' => $reservable,
                    'esencial' => $catalogo->esencial ?? false,
                    'requiere_aprobacion' => $catalogo->requiere_aprobacion ?? false,
                    'activa' => true,
                ])->id;
            }

            return $ids;
        });
    }

    /**
     * @return list<array{nombre: string, cantidad: int}>
     */
    private function registros(string $base, bool $reservable, int $cantidad, int $existentes, bool $delCatalogo): array
    {
        if ($reservable && $cantidad > 1) {
            return array_map(fn (int $n) => ['nombre' => "{$base} ".($existentes + $n), 'cantidad' => 1], range(1, $cantidad));
        }

        if ($reservable && $delCatalogo && $existentes > 0) {
            return [['nombre' => "{$base} ".($existentes + 1), 'cantidad' => 1]];
        }

        return [['nombre' => $cantidad > 1 ? "{$base} ({$cantidad})" : $base, 'cantidad' => $cantidad]];
    }
}
