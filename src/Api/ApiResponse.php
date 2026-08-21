<?php

declare(strict_types=1);

namespace PHPAML\Api;

use PHPAML\Http\Response;

final class ApiResponse
{
    public static function ok(mixed $data = null, int $status = 200): Response
    {
        return Response::json(['data' => $data], $status);
    }

    public static function created(mixed $data = null): Response
    {
        return self::ok($data, 201);
    }

    /** @param list<mixed> $data @param array<string, mixed> $meta */
    public static function collection(array $data, array $meta = []): Response
    {
        $payload = ['data' => $data];
        if ($meta !== []) { $payload['meta'] = $meta; }
        return Response::json($payload);
    }

    public static function noContent(): Response
    {
        return new Response('', 204, []);
    }

    /** @param array<string, mixed> $details */
    public static function error(string $code, string $message, int $status, array $details = []): Response
    {
        $error = array_merge(['code' => $code, 'message' => $message], $details);
        return Response::json(['error' => $error], $status);
    }

    /** @param array<string, list<string>> $fields */
    public static function validation(array $fields, string $message = 'Les données sont invalides.'): Response
    {
        return self::error('VALIDATION_FAILED', $message, 422, ['fields' => $fields]);
    }
}
