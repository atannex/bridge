<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Actions;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Input;

/** Protected with no rule: must refuse everyone. */
final class Locked implements Action
{
    public static int $runs = 0;

    public function handle(Input $input, Context $context): mixed
    {
        self::$runs++;

        return null;
    }
}
