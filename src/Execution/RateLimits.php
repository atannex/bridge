<?php

declare(strict_types=1);

namespace Atannex\Bridge\Execution;

use Atannex\Bridge\Exceptions\BridgeException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies Laravel named rate limiters (RateLimiter::for) to operations.
 * Limits with the same limiter name share a budget across operations.
 */
final class RateLimits
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function hit(string $name, Request $request): void
    {
        $callback = $this->limiter->limiter($name);

        if ($callback === null) {
            throw new LogicException("Bridge rate limiter [{$name}] is not defined. Define it with RateLimiter::for('{$name}', ...).");
        }

        $limits = $callback($request);

        if ($limits instanceof Response) {
            return;
        }

        foreach (is_array($limits) ? $limits : [$limits] as $limit) {
            if (! $limit instanceof Limit || $limit instanceof Unlimited) {
                continue;
            }

            $key = 'bridge:'.hash('sha256', $name.'|'.$limit->key.'|'.$limit->maxAttempts.'|'.$limit->decaySeconds);

            if ($this->limiter->tooManyAttempts($key, $limit->maxAttempts)) {
                throw BridgeException::rateLimited($this->limiter->availableIn($key));
            }

            $this->limiter->hit($key, $limit->decaySeconds);
        }
    }
}
