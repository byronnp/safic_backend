<?php

namespace App\Core\Tenancy\Http;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantDatabase;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Primera barrera: toma el condominio del header X-Condominio-Id, verifica que el
 * usuario tenga una membresía activa en él y lo fija para toda la petición
 * (contexto, equipo de spatie/laravel-permission y RLS).
 */
final class ResolveCondominio
{
    public const HEADER = 'X-Condominio-Id';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantDatabase $database,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $valor = $request->header(self::HEADER);

        if ($valor === null || ! ctype_digit((string) $valor) || (int) $valor < 1) {
            throw new ApiException('CONDOMINIO_REQUERIDO', 'Indica el condominio con el header X-Condominio-Id.', 400);
        }

        $condominioId = (int) $valor;

        /** @var User $user */
        $user = $request->user();

        if (! $user->tieneMembresiaActivaEn($condominioId)) {
            throw new ApiException('CONDOMINIO_NO_PERMITIDO', 'No tienes acceso a este condominio.', 403);
        }

        // Toda la petición corre en una transacción: RLS se fija con SET LOCAL y
        // muere con ella. Las excepciones del controlador llegan aquí ya
        // convertidas en respuesta, así que un error (4xx/5xx) deshace lo escrito.
        $this->database->beginTransaction();

        try {
            $this->context->set($condominioId);
            setPermissionsTeamId($condominioId);
            $user->unsetRelation('roles')->unsetRelation('permissions');

            $response = $next($request);
        } catch (Throwable $e) {
            $this->database->rollBack();
            $this->context->clear();

            throw $e;
        }

        if ($response->getStatusCode() < 400) {
            $this->database->commit();
        } else {
            $this->database->rollBack();
        }

        $this->context->clear();

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->context->has()) {
            $this->context->clear();
        }
    }
}
