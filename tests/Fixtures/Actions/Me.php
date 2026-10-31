<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Actions;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Contracts\HasOutput;
use Atannex\Bridge\Input;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Schema\Type;

final class Me implements Action, HasOutput
{
    public function output(): Type
    {
        return Schema::object([
            'id' => Schema::integer(),
            'guard' => Schema::string()->nullable(),
            'locale' => Schema::string(),
            'request_id' => Schema::string(),
            'client' => Schema::string()->nullable(),
        ]);
    }

    public function handle(Input $input, Context $context): mixed
    {
        return [
            'id' => $context->requireUser()->getAuthIdentifier(),
            'guard' => $context->guard,
            'locale' => $context->locale,
            'request_id' => $context->requestId,
            'client' => $context->client->client,
        ];
    }
}
