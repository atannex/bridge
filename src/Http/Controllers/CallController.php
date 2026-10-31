<?php

declare(strict_types=1);

namespace Atannex\Bridge\Http\Controllers;

use Atannex\Bridge\Execution\Executor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CallController
{
    public function __invoke(Request $request, Executor $executor, string $operation): JsonResponse
    {
        return $executor->handle($request, $operation);
    }
}
