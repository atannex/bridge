<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Actions;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Contracts\HasOutput;
use Atannex\Bridge\Input;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Schema\Type;

/** Returns a model whose schema names a key the model does not have. */
final class Leaky implements Action, HasOutput
{
    public function output(): Type
    {
        return Schema::object(['id' => Schema::integer(), 'nickname' => Schema::string()]);
    }

    public function handle(Input $input, Context $context): mixed
    {
        return $context->requireUser();
    }
}
