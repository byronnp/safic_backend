<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Finanzas\Models\Proveedor;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Caso de uso: dar de alta o editar un proveedor. El RUC no se repite en el condominio y no se
 * cambia una vez creado (las facturas lo identifican por él).
 */
final class GuardarProveedorAction
{
    /**
     * @param  array<string, mixed>  $datos  Validados por el controlador
     */
    public function crear(array $datos): Proveedor
    {
        try {
            return Proveedor::create($this->campos($datos) + ['ruc' => $datos['ruc'], 'activo' => true]);
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('PROVEEDOR_DUPLICADO', 'Ya hay un proveedor con ese RUC.', 409);
        }
    }

    /**
     * @param  array<string, mixed>  $datos  Solo lo que cambia
     */
    public function editar(int $id, array $datos): Proveedor
    {
        $proveedor = Proveedor::query()->findOrFail($id);
        $proveedor->fill($this->campos($datos));
        if (array_key_exists('activo', $datos)) {
            $proveedor->activo = (bool) $datos['activo'];
        }
        $proveedor->save();

        return $proveedor;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function campos(array $datos): array
    {
        return array_intersect_key($datos, array_flip(['razon_social', 'categoria', 'email', 'telefono']));
    }
}
