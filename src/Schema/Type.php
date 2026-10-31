<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Exceptions\OutputContractViolation;
use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;

/**
 * One node of a Bridge schema.
 *
 * A schema is the single source of truth for an operation's data: it builds
 * the Laravel validation rules for input, hydrates validated input into typed
 * values, allowlists and normalises output, and describes the TypeScript type.
 *
 * Types are immutable; every modifier returns a changed copy, so a shared
 * schema can be reused safely.
 */
abstract class Type
{
    protected bool $nullable = false;

    protected bool $optional = false;

    protected ?string $description = null;

    /** Lets the value be null. Nullable keys are always present in output. */
    public function nullable(bool $nullable = true): static
    {
        $copy = clone $this;
        $copy->nullable = $nullable;

        return $copy;
    }

    /** Lets an object key be left out. */
    public function optional(bool $optional = true): static
    {
        $copy = clone $this;
        $copy->optional = $optional;

        return $copy;
    }

    /** Text for the generated TSDoc comment and `bridge:list`. */
    public function description(string $description): static
    {
        $copy = clone $this;
        $copy->description = $description;

        return $copy;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Collect Laravel validation rules for the value at $path.
     *
     * Rules are expanded against the actual input (concrete paths such as
     * `items.0.quantity`, no wildcards), so the children of an absent or null
     * object are never checked and error keys match the client's data.
     *
     * @param  array<string, list<mixed>>  $rules
     */
    public function collectRules(mixed $value, string $path, array &$rules, bool $item = false): void
    {
        $rules[$path] = [...$this->presenceRules($item), ...$this->rules()];

        if ($value !== null && ! $value instanceof Missing) {
            $this->collectChildRules($value, $path, $rules);
        }
    }

    /**
     * Turn an already validated value into its typed PHP form.
     */
    public function hydrate(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return $this->cast($value);
    }

    /** The rules for this type's own value (not presence, not children). */
    /** @return list<mixed> */
    abstract protected function rules(): array;

    abstract protected function cast(mixed $value): mixed;

    /**
     * Normalise an outgoing value. Throws OutputContractViolation when the
     * value does not fit; never passes through anything the schema does not
     * declare.
     */
    abstract public function serialize(mixed $value, Serializer $serializer, string $path): mixed;

    /** The TypeScript type, without the `| null` for nullability. */
    abstract public function typescript(TypeScriptWriter $writer, Direction $direction): string;

    /** Short human name for diagnostics and `bridge:list`. */
    abstract public function label(): string;

    /**
     * @param  array<string, list<mixed>>  $rules
     */
    protected function collectChildRules(mixed $value, string $path, array &$rules): void
    {
        //
    }

    /**
     * Array items are never "missing", so they only need the null check;
     * keys need required / present / sometimes.
     *
     * @return list<string>
     */
    protected function presenceRules(bool $item): array
    {
        $rules = [];

        if (! $item) {
            if ($this->optional) {
                $rules[] = 'sometimes';
            }

            $rules[] = $this->allowsEmpty() || $this->nullable ? 'present' : 'required';
        }

        if ($this->nullable) {
            $rules[] = 'nullable';
        }

        return $rules;
    }

    /** Whether an empty string or empty list counts as a value. */
    protected function allowsEmpty(): bool
    {
        return false;
    }

    public function nullableTypeScript(TypeScriptWriter $writer, Direction $direction): string
    {
        $type = $this->typescript($writer, $direction);

        return $this->nullable ? $type.' | null' : $type;
    }

    protected function violation(string $path, string $expected, mixed $value): never
    {
        throw new OutputContractViolation($path, $expected, get_debug_type($value));
    }
}
