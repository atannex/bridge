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
use Atannex\Bridge\Tests\Fixtures\Status;

final class EchoInput implements Action, HasInput, HasOutput
{
    /** @return array<string, Type> */
    public static function fields(): array
    {
        return [
            'name' => Schema::string()->max(20),
            'count' => Schema::integer()->min(1)->max(10),
            'price' => Schema::decimal(2),
            'status' => Schema::enum(Status::class),
            'tags' => Schema::list(Schema::string()->max(10))->max(3),
            'address' => Schema::object([
                'city' => Schema::string(),
                'zip' => Schema::string()->optional(),
            ], 'Address')->optional(),
            'when' => Schema::dateTime()->optional(),
            'day' => Schema::date()->optional(),
            'scores' => Schema::record(Schema::integer())->optional(),
            'flag' => Schema::boolean()->optional(),
            'note' => Schema::string()->nullable(),
        ];
    }

    public function input(): ObjectType
    {
        return Schema::object(self::fields());
    }

    public function output(): Type
    {
        return Schema::object([
            ...self::fields(),
            'received' => Schema::record(Schema::string()),
        ], 'EchoResult');
    }

    public function handle(Input $input, Context $context): mixed
    {
        return $input->all() + [
            'received' => array_map(fn ($value) => get_debug_type($value), $input->all()),
        ];
    }
}
