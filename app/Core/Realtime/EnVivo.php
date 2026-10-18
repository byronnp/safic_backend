<?php

namespace App\Core\Realtime;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publicador de avisos en vivo para los módulos. Es de mejor esfuerzo: si el servidor de tiempo real no responde, la operación de
 * negocio (un ingreso, un paquete) sigue adelante y el cliente se entera con su consulta periódica.
 *
 * Canales (siempre privados y por condominio):
 * - condominio.{id}.usuario.{userId}: lo que le toca a una cuenta (solicitudes de ingreso, paquetes, avisos).
 * - condominio.{id}.garita: lo que necesita ver el equipo de garita (respuestas de los residentes).
 */
final class EnVivo
{
    public static function canalDeUsuario(int $condominioId, int $usuarioId): string
    {
        return "condominio.{$condominioId}.usuario.{$usuarioId}";
    }

    public static function canalDeGarita(int $condominioId): string
    {
        return "condominio.{$condominioId}.garita";
    }

    /** @param list<int> $usuarios */
    public static function aUsuarios(int $condominioId, array $usuarios, string $recurso, ?int $id = null): void
    {
        $canales = array_map(fn (int $u): string => self::canalDeUsuario($condominioId, $u), array_values(array_unique($usuarios)));

        self::publicar($canales, $recurso, $id);
    }

    public static function aGarita(int $condominioId, string $recurso, ?int $id = null): void
    {
        self::publicar([self::canalDeGarita($condominioId)], $recurso, $id);
    }

    /** @param list<string> $canales */
    private static function publicar(array $canales, string $recurso, ?int $id): void
    {
        if ($canales === []) {
            return;
        }

        try {
            ActualizacionEnVivo::dispatch($canales, $recurso, $id);
        } catch (Throwable $e) {
            Log::warning('No se pudo publicar el aviso en vivo.', ['recurso' => $recurso, 'error' => $e->getMessage()]);
        }
    }
}
