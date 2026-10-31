<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures;

enum Status: string
{
    case Draft = 'draft';
    case Published = 'published';
}
