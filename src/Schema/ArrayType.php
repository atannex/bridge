<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;
use Traversable;

/**
 * A list of values of one type.
 */
final class ArrayType extends Type
{
    /** Lists are capped by default so one request cannot expand into an enormous rule set. */
    public const DEFAULT_MAX_ITEMS = 1000;

    private ?int $min = null;

    private int $max = self::DEFAULT_MAX_ITEMS;

    public function __construct(private readonly Type $items) {}

    public function items(): Type
    {
        return $this->items;
    }

    public function min(int $items): self
    {
        $copy = clone $this;
        $copy->min = $items;

        return $copy;
    }

    public function max(int $items): self
    {
        $copy = clone $this;
        $copy->max = $items;

        return $copy;
    }

    /** @return list<mixed> */
    protected function rules(): array
    {
        $rules = ['array', 'list', 'max:'.$this->max];

        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }

        return $rules;
    }

    protected function allowsEmpty(): bool
    {
        return true;
    }

    protected function collectChildRules(mixed $value, string $path, array &$rules): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $this->max) {
            return;
        }

        foreach ($value as $index => $item) {
            $this->items->collectRules($item, $path.'.'.$index, $rules, item: true);
        }
    }

    protected function cast(mixed $value): mixed
    {
        return array_map(fn (mixed $item) => $this->items->hydrate($item), $value);
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        if ($value instanceof Traversable) {
            $value = iterator_to_array($value, false);
        }

        if (! is_array($value)) {
            $this->violation($path, 'list', $value);
        }

        $out = [];

        foreach (array_values($value) as $index => $item) {
            $out[] = $serializer->value($this->items, $item, $path.'.'.$index);
        }

        return $out;
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        $inner = $this->items->nullableTypeScript($writer, $direction);

        return str_contains($inner, ' ') && ! str_starts_with($inner, '{') ? '('.$inner.')[]' : $inner.'[]';
    }

    public function label(): string
    {
        return 'list<'.$this->items->label().'>';
    }
}
