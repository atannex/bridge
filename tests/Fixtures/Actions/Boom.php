<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Actions;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Input;
use RuntimeException;

final class Boom implements Action
{
    public function handle(Input $input, Context $context): mixed
    {
        throw new RuntimeException('SQLSTATE[42S02] secret_table at /var/www/app/secret.php');
    }
}
