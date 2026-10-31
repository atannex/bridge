<?php

declare(strict_types=1);

namespace Atannex\Bridge\Events;

/** A mobile bearer token was issued. Never carries the token. */
final readonly class TokenIssued
{
    public function __construct(
        public string|int $userId,
        public string $deviceName,
        public ?string $ip,
        public bool $afterTwoFactor,
    ) {}
}
