<?php

declare(strict_types=1);

namespace Atannex\Bridge\Events;

final readonly class TokenRevoked
{
    public function __construct(public string|int $userId, public ?string $ip) {}
}
