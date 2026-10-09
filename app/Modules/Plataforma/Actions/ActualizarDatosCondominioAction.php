<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: el administrador edita los datos de su condominio (nombre, contacto,
 * ubicación y colores). Los colores viven en `marca` junto a los logos, que no se tocan aquí.
 */
final class ActualizarDatosCondominioAction
{
    private const CAMPOS = ['nombre', 'telefono', 'email_contacto', 'direccion', 'provincia_codigo', 'canton_codigo', 'parroquia_codigo', 'latitud', 'longitud'];

    private const COLORES = ['color_primario', 'color_acento'];

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $datos  Validados por ActualizarDatosCondominioRequest
     */
    public function execute(array $datos): Condominio
    {
        return DB::transaction(function () use ($datos): Condominio {
            $condominio = Condominio::query()->lockForUpdate()->findOrFail($this->tenant->require());

            $condominio->fill(array_intersect_key($datos, array_flip(self::CAMPOS)));

            $colores = array_intersect_key($datos, array_flip(self::COLORES));
            if ($colores !== []) {
                $marca = array_merge($condominio->marca ?? [], $colores);
                // Restablecer (null) quita la clave: el frontend usa los colores de SAFIC
                $condominio->marca = array_filter($marca, fn ($valor) => $valor !== null);
            }

            $condominio->save();

            return $condominio->load(['provincia', 'canton', 'parroquia']);
        });
    }
}
