<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Actions;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Exceptions\Conflict;
use Atannex\Bridge\Input;

final class Conflicting implements Action
{
    public function handle(Input $input, Context $context): mixed
    {
        throw new Conflict('The cart is empty.', ['reason' => 'empty_cart']);
    }
}
