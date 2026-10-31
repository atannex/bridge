<?php

declare(strict_types=1);

namespace Atannex\Bridge\Execution;

use Illuminate\Contracts\Cache\Lock;

final readonly class Claim
{
    public function __construct(
        public string $id,
        public string $fingerprint,
        public Lock $lock,
    ) {}
}
