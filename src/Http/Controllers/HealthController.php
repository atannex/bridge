<?php

declare(strict_types=1);

namespace Atannex\Bridge\Http\Controllers;

use Atannex\Bridge\Http\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Liveness only. Deliberately says nothing about versions, drivers or
 * operations.
 */
final class HealthController
{
    public function __invoke(Request $request): JsonResponse
    {
        return Envelope::success($request, ['status' => 'ok']);
    }
}
