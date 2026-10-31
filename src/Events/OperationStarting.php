<?php

declare(strict_types=1);

namespace Atannex\Bridge\Events;

/**
 * Fired when a registered operation begins, before authentication. A hook
 * for tracing spans; pair it with OperationHandled by request id.
 */
final readonly class OperationStarting
{
    public function __construct(
        public string $operation,
        public string $requestId,
        public string $correlationId,
    ) {}
}
