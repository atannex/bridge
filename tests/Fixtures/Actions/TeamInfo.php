<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Actions;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Contracts\HasOutput;
use Atannex\Bridge\Input;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Schema\Type;

final class TeamInfo implements Action, HasOutput
{
    public function output(): Type
    {
        return Schema::object(['team' => Schema::string()]);
    }

    public function handle(Input $input, Context $context): mixed
    {
        return ['team' => (string) $context->tenant()->key];
    }
}
