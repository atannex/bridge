<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures\Actions;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Contracts\HasInput;
use Atannex\Bridge\Contracts\HasOutput;
use Atannex\Bridge\Files\Download;
use Atannex\Bridge\Input;
use Atannex\Bridge\Schema\ObjectType;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Schema\Type;

final class UploadAvatar implements Action, HasInput, HasOutput
{
    public function input(): ObjectType
    {
        return Schema::object([
            'avatar' => Schema::file()->image()->maxKilobytes(100),
            'caption' => Schema::string()->max(50)->optional(),
        ]);
    }

    public function output(): Type
    {
        return Schema::object([
            'size' => Schema::integer(),
            'mime' => Schema::string(),
            'caption' => Schema::string()->nullable(),
            'download' => Schema::download(),
        ]);
    }

    public function handle(Input $input, Context $context): mixed
    {
        $file = $input->file('avatar');
        $path = $file->storeAs('avatars', $context->requireUser()->getAuthIdentifier().'.png', 'local');

        return [
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
            'caption' => $input->get('caption'),
            'download' => Download::fromDisk('local', $path)->as('avatar.png'),
        ];
    }
}
