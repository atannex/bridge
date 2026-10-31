<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;
use Closure;

/**
 * A map from client-chosen string keys to values of one type.
 */
final class RecordType extends Type
{
    private const KEY = '/^[A-Za-z0-9_-]{1,100}$/';

    private int $max = 200;

    public function __construct(private readonly Type $values) {}

    public function max(int $keys): self
    {
        $copy = clone $this;
        $copy->max = $keys;

        return $copy;
    }

    /** @return list<mixed> */
    protected function rules(): array
    {
        return ['array', 'max:'.$this->max, $this->keyRule()];
    }

    protected function allowsEmpty(): bool
    {
        return true;
    }

    private function keyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            foreach (array_keys($value) as $key) {
                if (preg_match(self::KEY, (string) $key) !== 1) {
                    $fail('The :attribute keys may only contain letters, numbers, dashes and underscores.');

                    return;
                }
            }
        };
    }

    protected function collectChildRules(mixed $value, string $path, array &$rules): void
    {
        if (! is_array($value) || count($value) > $this->max) {
            return;
        }

        foreach ($value as $key => $item) {
            // A key with a dot would be read as a nested path; the key rule rejects it.
            if (preg_match(self::KEY, (string) $key) === 1) {
                $this->values->collectRules($item, $path.'.'.$key, $rules, item: true);
            }
        }
    }

    protected function cast(mixed $value): mixed
    {
        $out = [];

        foreach ($value as $key => $item) {
            $out[(string) $key] = $this->values->hydrate($item);
        }

        return $out;
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }

        if (! is_array($value)) {
            $this->violation($path, 'record', $value);
        }

        $out = [];

        foreach ($value as $key => $item) {
            $out[(string) $key] = $serializer->value($this->values, $item, $path.'.'.$key);
        }

        return $out === [] ? new \stdClass : $out;
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        return 'Record<string, '.$this->values->nullableTypeScript($writer, $direction).'>';
    }

    public function label(): string
    {
        return 'record<'.$this->values->label().'>';
    }
}
