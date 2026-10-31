<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;
use BackedEnum;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * A PHP backed enum, or a fixed list of string values. Travels as the backing
 * value; hydrates to the enum case.
 */
final class EnumType extends Type
{
    /** @var list<string|int> */
    private readonly array $values;

    /**
     * @param  class-string<BackedEnum>|null  $class
     * @param  list<string|int>  $values
     */
    private function __construct(private readonly ?string $class, array $values, private readonly ?string $name)
    {
        if ($values === []) {
            throw new InvalidArgumentException('An enum needs at least one value.');
        }

        $this->values = $values;
    }

    /**
     * @param  class-string<BackedEnum>  $class
     */
    public static function ofClass(string $class, ?string $name = null): self
    {
        if (! is_subclass_of($class, BackedEnum::class)) {
            throw new InvalidArgumentException("[{$class}] is not a backed enum. Bridge sends enums by their backing value.");
        }

        return new self($class, array_map(fn (BackedEnum $case) => $case->value, $class::cases()), $name ?? class_basename($class));
    }

    /**
     * @param  list<string|int>  $values
     */
    public static function ofValues(array $values, ?string $name = null): self
    {
        return new self(null, array_values($values), $name);
    }

    /** @return list<string|int> */
    public function values(): array
    {
        return $this->values;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    /** @return list<mixed> */
    protected function rules(): array
    {
        return [$this->class !== null ? Rule::enum($this->class) : Rule::in($this->values)];
    }

    protected function cast(mixed $value): mixed
    {
        if ($this->class === null) {
            return $value;
        }

        return $this->class::from(is_int($this->values[0]) ? (int) $value : (string) $value);
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        if ($value instanceof BackedEnum) {
            if ($this->class !== null && ! $value instanceof $this->class) {
                $this->violation($path, $this->label(), $value);
            }

            return $value->value;
        }

        if ((is_string($value) || is_int($value)) && in_array($value, $this->values, true)) {
            return $value;
        }

        $this->violation($path, $this->label(), $value);
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        $union = implode(' | ', array_map(
            fn (string|int $value) => is_int($value) ? (string) $value : TypeScriptWriter::quote($value),
            $this->values,
        ));

        return $this->name !== null ? $writer->alias($this->name, $union, $this) : $union;
    }

    public function label(): string
    {
        return 'enum'.($this->name !== null ? '('.$this->name.')' : '');
    }
}
