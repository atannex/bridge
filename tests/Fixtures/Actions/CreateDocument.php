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
use Atannex\Bridge\Tests\Fixtures\Document;

final class CreateDocument implements Action, HasInput, HasOutput
{
    public static int $runs = 0;

    public static function shape(): ObjectType
    {
        return Schema::object(['id' => Schema::integer(), 'title' => Schema::string(), 'user_id' => Schema::integer()], 'Document');
    }

    public function input(): ObjectType
    {
        return Schema::object(['title' => Schema::string()->max(50)]);
    }

    public function output(): Type
    {
        return self::shape();
    }

    public function handle(Input $input, Context $context): mixed
    {
        self::$runs++;

        // Passing all() straight to create() is safe: only `title` survives.
        return Document::query()->create($input->all() + ['user_id' => $context->requireUser()->getAuthIdentifier()]);
    }
}
