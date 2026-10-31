<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Actions;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Contracts\Authorizes;
use Atannex\Bridge\Contracts\HasInput;
use Atannex\Bridge\Contracts\HasOutput;
use Atannex\Bridge\Input;
use Atannex\Bridge\Schema\ObjectType;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Schema\Type;
use Atannex\Bridge\Tests\Fixtures\Document;
use Illuminate\Auth\Access\Response;

final class ShowDocument implements Action, Authorizes, HasInput, HasOutput
{
    public function input(): ObjectType
    {
        return Schema::object(['id' => Schema::integer()]);
    }

    public function output(): Type
    {
        return CreateDocument::shape();
    }

    public function authorize(Input $input, Context $context): Response
    {
        $document = Document::query()->find((int) $input['id']);

        // Someone else's document looks exactly like a missing one.
        return $document !== null && $document->user_id === $context->requireUser()->getAuthIdentifier()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function handle(Input $input, Context $context): mixed
    {
        return Document::query()->findOrFail($input['id']);
    }
}
