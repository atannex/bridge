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

final class ListUsers implements Action, HasInput, HasOutput
{
    public function input(): ObjectType
    {
        return Schema::object([
            'page' => Schema::integer()->min(1)->optional(),
            'mode' => Schema::enum(['length', 'simple', 'cursor'], 'PageMode')->optional(),
            'cursor' => Schema::string()->max(500)->nullable()->optional(),
        ]);
    }

    public function output(): Type
    {
        return Schema::paginated(ShowUser::shape());
    }

    public function handle(Input $input, Context $context): mixed
    {
        $query = User::query()->orderBy('id');

        return match ($input->get('mode', 'length')) {
            'simple' => $query->simplePaginate(2, page: $input->get('page', 1)),
            'cursor' => $query->cursorPaginate(2, cursor: $input->get('cursor')),
            default => $query->paginate(2, page: $input->get('page', 1)),
        };
    }
}
