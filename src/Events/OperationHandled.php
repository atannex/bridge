<?php

declare(strict_types=1);

namespace Atannex\Bridge\Events;

/**
 * Fired after every call, success or failure. Carries no input, output or
 * credentials, so it is safe to log or forward to metrics and tracing.
 */
final readonly class OperationHandled
{
    public function __construct(
        public ?string $operation,
        public string $requestId,
        public string $correlationId,
        public int $status,
        public ?string $errorCode,
        public float $durationMs,
        public string|int|null $userId,
        public string|int|null $tenant,
        public bool $replayed,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return get_object_vars($this);
    }
}
