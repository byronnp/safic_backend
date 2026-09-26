<?php

namespace App\Core\Http\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Convierte cualquier excepción de la API al formato { "error": {...} }.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return self::toResponse($e);
        });
    }

    public static function toResponse(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => $e->render(),
            $e instanceof ValidationException => ApiErrorResponse::make('VALIDACION', 'Revisa los datos enviados.', 422, fields: $e->errors()),
            $e instanceof AuthenticationException => ApiErrorResponse::make('NO_AUTENTICADO', 'Tu sesión no es válida o expiró.', 401),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException,
            $e instanceof UnauthorizedException => ApiErrorResponse::make('SIN_PERMISO', 'No tienes permiso para esta acción.', 403),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiErrorResponse::make('NO_ENCONTRADO', 'El recurso no existe.', 404),
            $e instanceof ThrottleRequestsException => ApiErrorResponse::make('DEMASIADOS_INTENTOS', 'Demasiados intentos. Espera un momento.', 429),
            $e instanceof HttpExceptionInterface => ApiErrorResponse::make('HTTP_'.$e->getStatusCode(), $e->getMessage() ?: 'Error en la petición.', $e->getStatusCode()),
            default => config('app.debug') ? null : ApiErrorResponse::make('ERROR_INTERNO', 'Ocurrió un error inesperado.', 500),
        };
    }
}
