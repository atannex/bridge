<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

/**
 * Marks a key that is absent, as opposed to present with null.
 */
final class Missing
{
    private static ?self $instance = null;

    public static function value(): self
    {
        return self::$instance ??= new self;
    }
}
