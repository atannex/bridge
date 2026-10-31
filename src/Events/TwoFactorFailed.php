<?php

declare(strict_types=1);

namespace Atannex\Bridge\Events;

final readonly class TwoFactorFailed
{
    public function __construct(public string|int $userId, public ?string $ip, public bool $challengeBurned) {}
}
