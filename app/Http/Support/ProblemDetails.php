<?php

namespace App\Http\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ProblemDetails
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function response(
        Request $request,
        int $status,
        string $code,
        string $title,
        string $detail,
        array $errors = [],
    ): JsonResponse {
        $requestId = $request->attributes->get('request_id');
        if (! is_string($requestId) || $requestId === '') {
            $requestId = (string) Str::uuid();
            $request->attributes->set('request_id', $requestId);
        }

        $payload = [
            'type' => 'https://api.tupay.test/problems/'.strtolower(str_replace('_', '-', $code)),
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
            'request_id' => $requestId,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json(
            $payload,
            $status,
            [
                'Content-Type' => 'application/problem+json',
                'X-Request-ID' => $requestId,
            ],
        );
    }
}
