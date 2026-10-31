<?php

declare(strict_types=1);

namespace Atannex\Bridge\Contracts;

use Atannex\Bridge\Context;
use Atannex\Bridge\Input;

/**
 * An operation clients may call. Actions are resolved from the container, so
 * constructor injection works; `handle` receives only validated input and the
 * trusted server-side context.
 *
 * Optional extras: HasInput, HasOutput, Authorizes.
 */
interface Action
{
    public function handle(Input $input, Context $context): mixed;
}
