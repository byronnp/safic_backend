<?php

namespace App\Core\Http\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Error de negocio con código estable que el frontend puede traducir.
 * Ej.: throw new ApiException('LIMITE_UNIDADES', 'Tu plan permite 130 unidades.', 422);
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return ApiErrorResponse::make($this->errorCode, $this->getMessage(), $this->status, $this->details);
    }
}
