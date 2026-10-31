<?php

declare(strict_types=1);

namespace Atannex\Bridge\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void action(string $name, string $class, array<string, mixed> $options = [])
 * @method static void event(string $class, array<string, mixed> $options = [])
 * @method static void resolveTenantsUsing(\Closure|string $resolver)
 * @method static void resolveUsersUsing(\Closure|null $resolver)
 * @method static void presentUsersUsing(\Closure|null $presenter)
 * @method static void authenticateTokensUsing(\Closure|null $callback)
 * @method static \Atannex\Bridge\Registry\Registry registry()
 *
 * @see \Atannex\Bridge\Bridge
 */
final class Bridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Atannex\Bridge\Bridge::class;
    }
}
