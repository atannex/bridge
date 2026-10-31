<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Actions;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Contracts\HasOutput;
use Atannex\Bridge\Input;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Schema\Type;

final class AdminStats implements Action, HasOutput
{
    public static int $runs = 0;

    public function output(): Type
    {
        return Schema::object(['users' => Schema::integer()]);
    }

    public function handle(Input $input, Context $context): mixed
    {
        self::$runs++;

        return ['users' => 42];
    }
}
