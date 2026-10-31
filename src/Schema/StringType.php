<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;
use Stringable;

final class StringType extends Type
{
    private ?int $min = null;

    private ?int $max = null;

    private bool $empty = false;

    /** @var list<mixed> */
    private array $extra = [];

    public function min(int $length): self
    {
        $copy = clone $this;
        $copy->min = $length;

        return $copy;
    }

    public function max(int $length): self
    {
        $copy = clone $this;
        $copy->max = $length;

        return $copy;
    }

    /** Accept "" as a value of a required key. */
    public function allowEmptyString(): self
    {
        $copy = clone $this;
        $copy->empty = true;

        return $copy;
    }

    public function email(): self
    {
        return $this->rule('email');
    }

    public function url(): self
    {
        return $this->rule('url:https,http');
    }

    public function uuid(): self
    {
        return $this->rule('uuid');
    }

    public function regex(string $pattern): self
    {
        return $this->rule('regex:'.$pattern);
    }

    /**
     * Any extra Laravel rule for this value (string or rule object). It only
     * affects validation, not the generated type.
     */
    public function rule(mixed $rule): self
    {
        $copy = clone $this;
        $copy->extra[] = $rule;

        return $copy;
    }

    /** @return list<mixed> */
    protected function rules(): array
    {
        $rules = ['string'];

        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }

        if ($this->max !== null) {
            $rules[] = 'max:'.$this->max;
        }

        return [...$rules, ...$this->extra];
    }

    protected function allowsEmpty(): bool
    {
        return $this->empty;
    }

    protected function cast(mixed $value): mixed
    {
        return (string) $value;
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        $this->violation($path, 'string', $value);
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        return 'string';
    }

    public function label(): string
    {
        return 'string';
    }
}
