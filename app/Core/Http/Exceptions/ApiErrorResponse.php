<?php

namespace App\Core\Http\Exceptions;

use Illuminate\Http\JsonResponse;

final class ApiErrorResponse
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, array<int, string>>  $fields
     */
    public static function make(string $code, string $message, int $status, array $details = [], array $fields = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }
}
