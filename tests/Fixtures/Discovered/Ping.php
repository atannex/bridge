<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Discovered;

use Atannex\Bridge\Attributes\Access;
use Atannex\Bridge\Attributes\Idempotency;
use Atannex\Bridge\Attributes\Operation;
use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Contracts\HasOutput;
use Atannex\Bridge\Input;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Schema\Type;

#[Operation('system.ping', access: Access::Guest, idempotency: Idempotency::Safe, description: 'Says pong.')]
final class Ping implements Action, HasOutput
{
    public function output(): Type
    {
        return Schema::object(['pong' => Schema::boolean()]);
    }

    public function handle(Input $input, Context $context): mixed
    {
        return ['pong' => true];
    }
}
