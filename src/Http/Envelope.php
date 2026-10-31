<?php

declare(strict_types=1);

namespace Atannex\Bridge\Http;

use Atannex\Bridge\Exceptions\BridgeException;
use Atannex\Bridge\Protocol;
use Atannex\Bridge\Support\RequestIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Builds protocol responses: `{data, meta}` or `{error, meta}`.
 */
final class Envelope
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function success(Request $request, mixed $data, array $meta = [], array $headers = []): JsonResponse
    {
        return self::respond($request, ['data' => $data], 200, $meta, $headers);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function error(Request $request, BridgeException $error, array $meta = []): JsonResponse
    {
        $body = ['code' => $error->errorCode, 'message' => $error->getMessage()];

        if ($error->details !== null) {
            $body['details'] = $error->details;
        }

        return self::respond($request, ['error' => $body], $error->status, $meta, $error->headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function respond(Request $request, array $payload, int $status, array $meta = [], array $headers = []): JsonResponse
    {
        [$requestId, $correlationId] = RequestIds::for($request);

        $payload['meta'] = ['request_id' => $requestId, 'version' => Protocol::VERSION] + $meta;

        $response = new JsonResponse(null, $status, $headers + [
            'X-Request-Id' => $requestId,
            'X-Correlation-Id' => $correlationId,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ], self::JSON_FLAGS);

        try {
            $response->setData($payload);
        } catch (\InvalidArgumentException $e) {
            report($e);
            $response->setStatusCode(500);
            $response->setData([
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => BridgeException::internal()->getMessage()],
                'meta' => $payload['meta'],
            ]);
        }

        return $response;
    }
}
