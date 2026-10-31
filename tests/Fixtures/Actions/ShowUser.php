<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Actions;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Contracts\HasInput;
use Atannex\Bridge\Contracts\HasOutput;
use Atannex\Bridge\Input;
use Atannex\Bridge\Schema\ObjectType;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Schema\Type;
use Atannex\Bridge\Tests\Fixtures\User;

final class ShowUser implements Action, HasInput, HasOutput
{
    public static function shape(): ObjectType
    {
        return Schema::object([
            'id' => Schema::integer(),
            'name' => Schema::string(),
            'is_admin' => Schema::boolean(),
            'created_at' => Schema::dateTime()->nullable(),
        ], 'PublicUser');
    }

    public function input(): ObjectType
    {
        return Schema::object(['id' => Schema::integer()]);
    }

    public function output(): Type
    {
        return self::shape();
    }

    public function handle(Input $input, Context $context): mixed
    {
        return User::query()->findOrFail($input['id']);
    }
}
