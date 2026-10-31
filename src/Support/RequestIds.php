<?php

declare(strict_types=1);

namespace Atannex\Bridge\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Request and correlation ids. A client-supplied id is kept only if it looks
 * like an id (so it cannot inject into logs); otherwise one is generated.
 * Stored on the request so errors raised by middleware share it.
 */
final class RequestIds
{
    public const PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';

    /**
     * @return array{0: string, 1: string}
     */
    public static function for(Request $request): array
    {
        if (! $request->attributes->has('bridge.request_id')) {
            $requestId = self::accept($request->headers->get('X-Request-Id')) ?? (string) Str::ulid();
            $correlationId = self::accept($request->headers->get('X-Correlation-Id')) ?? $requestId;

            $request->attributes->set('bridge.request_id', $requestId);
            $request->attributes->set('bridge.correlation_id', $correlationId);
        }

        return [$request->attributes->get('bridge.request_id'), $request->attributes->get('bridge.correlation_id')];
    }

    public static function accept(?string $value): ?string
    {
        return $value !== null && preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }
}
