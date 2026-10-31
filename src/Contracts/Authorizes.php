<?php

declare(strict_types=1);

namespace Atannex\Bridge\Contracts;

use Atannex\Bridge\Context;
use Atannex\Bridge\Input;
use Illuminate\Auth\Access\Response;

/**
 * Record-level or input-dependent authorization. Runs after validation and
 * before `handle`. Return false (or a denied Response) to refuse; a Response
 * built with denyAsNotFound() answers 404 instead of 403.
 */
interface Authorizes
{
    public function authorize(Input $input, Context $context): bool|Response;
}
