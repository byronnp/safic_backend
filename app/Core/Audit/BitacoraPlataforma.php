<?php

namespace App\Core\Audit;

use Illuminate\Support\Str;

/**
 * Escribe en la bitácora de plataforma. Lo usan el trait RegistraBitacora (modelos) y las
 * acciones que cambian algo que no es un modelo (permisos de un rol, perfiles de un ítem).
 *
 * Solo se registra cuando hay una persona autenticada: los seeders y las tareas del
 * sistema no son decisiones de nadie. Se escribe dentro de la transacción del cambio, así
 * que si el cambio se revierte, el registro también.
 */
final class BitacoraPlataforma
{
    /** Nunca se guardan: datos personales y secretos. */
    private const SENSIBLES = ['password', 'remember_token', 'email', 'cedula', 'telefono', 'email_contacto'];

    /**
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>|null  $despues
     */
    public static function registrar(
        string $evento,
        string $entidad,
        int|string|null $entidadId,
        ?string $etiqueta,
        ?array $antes,
        ?array $despues,
        ?int $condominioId = null,
    ): void {
        $usuario = auth('api')->user();
        if ($usuario === null) {
            return;
        }

        $antes = self::limpiar($antes);
        $despues = self::limpiar($despues);
        if ($evento === 'actualizado' && $antes === [] && $despues === []) {
            return;
        }

        $request = app()->runningInConsole() ? null : request();

        RegistroBitacora::create([
            'user_id' => $usuario->getAuthIdentifier(),
            'user_nombre' => Str::limit((string) ($usuario->name ?? ''), 120, ''),
            'evento' => $evento,
            'entidad' => $entidad,
            'entidad_id' => $entidadId === null ? null : (string) $entidadId,
            'etiqueta' => $etiqueta === null ? null : Str::limit($etiqueta, 160, ''),
            'condominio_id' => $condominioId,
            'valores_anteriores' => $antes === [] ? null : $antes,
            'valores_nuevos' => $despues === [] ? null : $despues,
            'ip' => $request?->ip(),
            'user_agent' => $request === null ? null : Str::limit((string) $request->userAgent(), 255, ''),
            'url' => $request === null ? null : Str::limit($request->method().' /'.$request->path(), 255, ''),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $valores
     * @return array<string, mixed>
     */
    private static function limpiar(?array $valores): array
    {
        return array_diff_key($valores ?? [], array_flip(self::SENSIBLES));
    }
}
