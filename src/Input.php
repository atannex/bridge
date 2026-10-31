<?php

declare(strict_types=1);

namespace Atannex\Bridge;

use ArrayAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use LogicException;

/**
 * Validated, typed input. Holds only the keys the input schema declares;
 * anything else the client sent was dropped before the action runs, so it
 * is safe to pass to fill() / create() for exactly those keys.
 *
 * @implements ArrayAccess<string, mixed>
 */
final readonly class Input implements ArrayAccess
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values = []) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /** Dot paths work: get('address.city'). */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->values, $key, $default);
    }

    /** True when the key was sent (even as null). */
    public function has(string $key): bool
    {
        return Arr::has($this->values, $key);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return Arr::only($this->values, $keys);
    }

    public function file(string $key): ?UploadedFile
    {
        $value = $this->get($key);

        return $value instanceof UploadedFile ? $value : null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Bridge input is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Bridge input is read-only.');
    }
}
