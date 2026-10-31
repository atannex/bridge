<?php

declare(strict_types=1);

namespace Atannex\Bridge\Contracts;

use Atannex\Bridge\Schema\Type;

/**
 * Declares the output. Only what the schema lists is sent. Without it the
 * action must return null.
 */
interface HasOutput
{
    public function output(): Type;
}
