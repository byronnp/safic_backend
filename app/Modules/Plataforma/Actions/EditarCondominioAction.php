<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\TenantContext;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Plataforma\Models\Plan;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del super admin: editar los datos de cualquier condominio. El cambio queda en la
 * bitácora de plataforma (el modelo la registra). No se baja el total de unidades por debajo de las
 * ya registradas, y la ubicación (provincia, cantón, parroquia) debe ser coherente entre sí.
 */
final class EditarCondominioAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    private const CAMPOS = [
        'nombre', 'tipo', 'ruc', 'razon_social', 'provincia_codigo', 'canton_codigo', 'parroquia_codigo',
        'direccion', 'telefono', 'email_contacto', 'total_unidades', 'valor_unidad', 'latitud', 'longitud',
    ];

    /**
     * @param  array<string, mixed>  $datos  Validados por EditarCondominioRequest
     */
    public function execute(int $id, array $datos): Condominio
    {
        return DB::transaction(function () use ($id, $datos): Condominio {
            $condominio = Condominio::query()->lockForUpdate()->findOrFail($id);
            $condominio->fill(array_intersect_key($datos, array_flip(self::CAMPOS)));

            if (isset($datos['plan_codigo'])) {
                $condominio->plan_id = Plan::query()->where('codigo', $datos['plan_codigo'])->where('activo', true)->firstOrFail()->id;
            }

            if (isset($datos['total_unidades'])) {
                // Las unidades están bajo RLS: se cuentan dentro del contexto de ese condominio
                $registradas = $this->tenant->run($condominio->id, fn (): int => Unidad::query()->count());
                if ((int) $datos['total_unidades'] < $registradas) {
                    throw new ApiException('UNIDADES_REGISTRADAS', "El condominio ya tiene {$registradas} unidades registradas: el total no puede ser menor.", 422, ['registradas' => $registradas]);
                }
            }

            $this->validarUbicacion($condominio);
            $condominio->save();

            return $condominio->load(['plan', 'provincia', 'canton', 'parroquia']);
        });
    }

    /** Cantón dentro de su provincia y parroquia dentro de su cantón. */
    private function validarUbicacion(Condominio $c): void
    {
        if (! $c->isDirty(['provincia_codigo', 'canton_codigo', 'parroquia_codigo'])) {
            return;
        }

        $canton = DB::table('ubicacion_cantones')->where('codigo', $c->canton_codigo)->first();
        $parroquia = DB::table('ubicacion_parroquias')->where('codigo', $c->parroquia_codigo)->first();

        if ($canton === null || $canton->provincia_codigo !== $c->provincia_codigo) {
            throw new ApiException('UBICACION_INCOHERENTE', 'El cantón no pertenece a la provincia elegida.', 422);
        }
        if ($parroquia === null || $parroquia->canton_codigo !== $c->canton_codigo) {
            throw new ApiException('UBICACION_INCOHERENTE', 'La parroquia no pertenece al cantón elegido.', 422);
        }
    }
}
