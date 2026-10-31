<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Discovered;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Input;

/** Implements Action but carries no #[Operation]: must not be exposed. */
final class NotAnOperation implements Action
{
    public function handle(Input $input, Context $context): mixed
    {
        return null;
    }
}
