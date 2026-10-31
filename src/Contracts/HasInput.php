<?php

declare(strict_types=1);

namespace Atannex\Bridge\Contracts;

use Atannex\Bridge\Schema\ObjectType;

/**
 * Declares the input. Without it an action takes no input: every key the
 * client sends is dropped.
 */
interface HasInput
{
    public function input(): ObjectType;
}
