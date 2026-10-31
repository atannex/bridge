<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;

final class IntegerType extends Type
{
    private ?int $min = null;

    private ?int $max = null;

    public function min(int $min): self
    {
        $copy = clone $this;
        $copy->min = $min;

        return $copy;
    }

    public function max(int $max): self
    {
        $copy = clone $this;
        $copy->max = $max;

        return $copy;
    }

    /** @return list<mixed> */
    protected function rules(): array
    {
        // `integer:strict` would reject "5"; JSON clients send numbers, and
        // multipart input is JSON too, so plain integer is enough here and the
        // value is cast below.
        $rules = ['integer'];

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
        return (int) $value;
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        if (is_int($value)) {
            return $value;
        }

        // Some database drivers hand integers back as strings.
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
            return (int) $value;
        }

        $this->violation($path, 'integer', $value);
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        return 'number';
    }

    public function label(): string
    {
        return 'integer';
    }
}
