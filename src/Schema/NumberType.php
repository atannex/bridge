<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;

/**
 * A float. Use DecimalType for money and anything else that must not lose
 * precision.
 */
final class NumberType extends Type
{
    private int|float|null $min = null;

    private int|float|null $max = null;

    public function min(int|float $min): self
    {
        $copy = clone $this;
        $copy->min = $min;

        return $copy;
    }

    public function max(int|float $max): self
    {
        $copy = clone $this;
        $copy->max = $max;

        return $copy;
    }

    /** @return list<mixed> */
    protected function rules(): array
    {
        $rules = ['numeric'];

        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }

        if ($this->max !== null) {
            $rules[] = 'max:'.$this->max;
        }

        return $rules;
    }

    protected function cast(mixed $value): mixed
    {
        return (float) $value;
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        if (is_int($value) || is_float($value)) {
            if (is_float($value) && ! is_finite($value)) {
                $this->violation($path, 'finite number', $value);
            }

            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return $value + 0;
        }

        $this->violation($path, 'number', $value);
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        return 'number';
    }

    public function label(): string
    {
        return 'number';
    }
}
