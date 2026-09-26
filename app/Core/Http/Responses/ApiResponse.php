<?php

namespace App\Core\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;

/**
 * Formato único de respuesta de la API. Los controladores nunca usan
 * response()->json() directamente.
 *
 * Éxito:   { "data": ..., "meta": {...}, "message": "..." }
 * Error:   { "error": { "code": "...", "message": "...", "fields": {...} } }  (ver ApiException)
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(mixed $data = null, array $meta = [], ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(self::body($data, $meta, $message), $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function created(mixed $data = null, array $meta = [], ?string $message = null): JsonResponse
    {
        return self::ok($data, $meta, $message, 201);
    }

    /**
     * Operación aceptada que termina en segundo plano (ej. importación Excel).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function accepted(mixed $data = null, array $meta = [], ?string $message = null): JsonResponse
    {
        return self::ok($data, $meta, $message, 202);
    }

    public static function noContent(): Response
    {
        return response()->noContent();
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  class-string<JsonResource>  $resource
     */
    public static function paginated(LengthAwarePaginator $paginator, string $resource): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($paginator->items())->resolve(),
            'meta' => [
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function body(mixed $data, array $meta, ?string $message): array
    {
        if ($data instanceof JsonResource || $data instanceof ResourceCollection) {
            $data = $data->resolve();
        }

        return array_filter([
            'data' => $data,
            'meta' => $meta === [] ? null : $meta,
            'message' => $message,
        ], fn ($value) => $value !== null) + ['data' => null];
    }
}
