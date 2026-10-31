<?php

declare(strict_types=1);

namespace Atannex\Bridge\Authentication;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

/**
 * Finds the signed-in user using the app's own guards. Bridge never checks
 * passwords or tokens itself for operation calls; it asks Laravel.
 */
final class PrincipalResolver
{
    private ?Closure $custom = null;

    /**
     * @param  list<string>|null  $guards  null means the app's default guard
     */
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly ?array $guards,
    ) {}

    /**
     * Replace guard lookup, e.g. for a non-Laravel identity provider.
     * The closure gets the Request and returns an Authenticatable or null.
     */
    public function using(?Closure $resolver): void
    {
        $this->custom = $resolver;
    }

    /**
     * @return array{0: Authenticatable|null, 1: string|null} user and guard name
     */
    public function resolve(Request $request): array
    {
        if ($this->custom !== null) {
            return [($this->custom)($request), null];
        }

        foreach ($this->guards ?? [null] as $guard) {
            $user = $this->auth->guard($guard)->user();

            if ($user !== null) {
                // Let app code that calls auth()->user() see the same user.
                $this->auth->shouldUse($guard ?? config('auth.defaults.guard'));

                return [$user, $guard ?? config('auth.defaults.guard')];
            }
        }

        return [null, null];
    }
}
