<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Actions;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Input;

/** No output schema; returns $value. */
final class Nothing implements Action
{
    public static mixed $value = null;

    public function handle(Input $input, Context $context): mixed
    {
        return self::$value;
    }
}
