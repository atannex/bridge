<?php

declare(strict_types=1);

namespace Atannex\Bridge\Exceptions;

use LogicException;

final class InvalidOperation extends LogicException
{
    public static function badName(string $name): self
    {
        return new self("Bridge name [{$name}] is invalid. Use lowercase dot-separated segments such as [orders.create] (letters, digits, underscores; at most 120 characters).");
    }

    public static function notAnAction(string $name, string $class): self
    {
        return new self("Bridge operation [{$name}] points at [{$class}], which is not a class implementing Atannex\\Bridge\\Contracts\\Action.");
    }

    public static function notAnEvent(string $name, string $class): self
    {
        return new self("Bridge event [{$name}] points at [{$class}], which does not implement Atannex\\Bridge\\Contracts\\BroadcastEvent.");
    }

    public static function eventWithoutName(string $class): self
    {
        return new self("Bridge event [{$class}] has no name. Add #[Event('name')] or pass ['name' => ...].");
    }

    public static function onWithoutCan(string $name): self
    {
        return new self("Bridge operation [{$name}] sets `on` without `can`. Name the ability to check.");
    }

    /**
     * @param  list<string>  $keys
     */
    public static function unknownOptions(string $name, array $keys): self
    {
        return new self("Bridge operation [{$name}] has unknown option(s): ".implode(', ', $keys).'.');
    }

    public static function noTenantResolver(string $name): self
    {
        return new self("Bridge operation [{$name}] uses tenants, but no tenant resolver is configured. Call Bridge::resolveTenantsUsing() or set bridge.tenancy.resolver.");
    }
}
