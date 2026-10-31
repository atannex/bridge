<?php

declare(strict_types=1);

namespace Atannex\Bridge\Exceptions;

use LogicException;

final class DuplicateOperation extends LogicException
{
    public static function operation(string $name, string $first, string $second): self
    {
        return new self("Bridge operation [{$name}] is defined twice: by [{$first}] and by [{$second}]. Operation names must be unique; rename one of them.");
    }

    public static function event(string $name, string $first, string $second): self
    {
        return new self("Bridge event [{$name}] is defined twice: by [{$first}] and by [{$second}].");
    }
}
