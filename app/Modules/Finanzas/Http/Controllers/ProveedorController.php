<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Finanzas\Actions\CambiarCuentaProveedorAction;
use App\Modules\Finanzas\Actions\GuardarProveedorAction;
use App\Modules\Finanzas\Actions\ListarProveedoresAction;
use App\Modules\Finanzas\Models\ProveedorCuenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Proveedores del condominio y la cuenta bancaria donde se les paga.
 */
class ProveedorController
{
    public function index(Request $request, ListarProveedoresAction $listar): JsonResponse
    {
        $f = $request->validate([
            'filtro' => ['sometimes', Rule::in(ListarProveedoresAction::FILTROS)],
            'buscar' => ['sometimes', 'nullable', 'string', 'max:80'],
        ], ['filtro.in' => 'Elige todos, con saldo o cuenta en cambio.']);

        $r = $listar->execute($f['filtro'] ?? 'todos', trim((string) ($f['buscar'] ?? '')));

        return ApiResponse::ok($r['proveedores'], ['conteos' => $r['conteos']]);
    }

    public function store(Request $request, GuardarProveedorAction $guardar, ListarProveedoresAction $listar): JsonResponse
    {
        $datos = $request->validate([
            'ruc' => ['required', 'string', 'regex:/^\d{13}$/'],
            ...self::reglas(true),
        ], self::mensajes());

        $proveedor = $guardar->crear($datos);

        return ApiResponse::created($listar->uno($proveedor->id), message: 'Proveedor agregado.');
    }

    public function update(Request $request, GuardarProveedorAction $guardar, ListarProveedoresAction $listar, int $proveedor): JsonResponse
    {
        $datos = $request->validate(self::reglas(false) + ['activo' => ['sometimes', 'boolean']], self::mensajes());

        $guardar->editar($proveedor, $datos);

        return ApiResponse::ok($listar->uno($proveedor), message: 'Cambios guardados.');
    }

    public function cambiarCuenta(Request $request, CambiarCuentaProveedorAction $cambiar, ListarProveedoresAction $listar, int $proveedor): JsonResponse
    {
        $datos = $request->validate([
            'banco' => ['required', 'string', 'min:2', 'max:60'],
            'tipo' => ['required', Rule::in(ProveedorCuenta::TIPOS)],
            'numero' => ['required', 'string', 'regex:/^[0-9\-]{6,30}$/'],
            'titular' => ['required', 'string', 'min:3', 'max:120'],
            'password' => ['required', 'string'],
        ], [
            'banco.required' => 'Escribe el banco.',
            'tipo.required' => 'Elige corriente o ahorros.',
            'tipo.in' => 'Elige corriente o ahorros.',
            'numero.required' => 'Escribe el número de cuenta.',
            'numero.regex' => 'El número de cuenta solo lleva dígitos y guiones (6 a 30).',
            'titular.required' => 'Escribe el titular de la cuenta.',
            'password.required' => 'Escribe tu contraseña para confirmar el cambio.',
        ]);

        $user = $request->user();
        $cambiar->solicitar($user, $proveedor, [
            'banco' => $datos['banco'], 'tipo' => $datos['tipo'], 'numero' => $datos['numero'], 'titular' => $datos['titular'],
        ], $datos['password']);

        return ApiResponse::created($listar->uno($proveedor), message: 'Cambio de cuenta solicitado.');
    }

    public function detenerCambio(CambiarCuentaProveedorAction $cambiar, ListarProveedoresAction $listar, int $proveedor): JsonResponse
    {
        $cambiar->detener($proveedor);

        return ApiResponse::ok($listar->uno($proveedor), message: 'Cambio de cuenta detenido.');
    }

    /** @return array<string, list<string>> */
    private static function reglas(bool $exigir): array
    {
        $req = $exigir ? 'required' : 'sometimes';

        return [
            'razon_social' => [$req, 'string', 'min:3', 'max:160'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:60'],
            'email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }

    /** @return array<string, string> */
    private static function mensajes(): array
    {
        return [
            'ruc.required' => 'Escribe el RUC.',
            'ruc.regex' => 'El RUC tiene 13 dígitos.',
            'razon_social.required' => 'Escribe el nombre del proveedor.',
            'razon_social.min' => 'El nombre es muy corto.',
            'email.email' => 'El correo no es válido.',
        ];
    }
}
