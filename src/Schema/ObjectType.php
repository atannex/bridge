<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Exceptions\OutputContractViolation;
use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;
use InvalidArgumentException;

/**
 * An object with a fixed set of keys. Keys not listed are dropped from input
 * and never sent in output, which is what keeps Bridge from leaking model
 * attributes or accepting mass-assignment payloads.
 */
final class ObjectType extends Type
{
    /**
     * @param  array<string, Type>  $fields
     */
    public function __construct(private readonly array $fields, private readonly ?string $name = null)
    {
        foreach ($fields as $key => $type) {
            if (! is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw new InvalidArgumentException("Schema key [{$key}] must be a plain identifier.");
            }

            if (! $type instanceof Type) {
                throw new InvalidArgumentException("Schema key [{$key}] must map to a Bridge schema type.");
            }
        }

        if ($name !== null && preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Schema name [{$name}] must be PascalCase.");
        }
    }

    /** Give the object a name, so it is generated as a named TypeScript interface. */
    public function named(string $name): self
    {
        $copy = new self($this->fields, $name);
        $copy->nullable = $this->nullable;
        $copy->optional = $this->optional;
        $copy->description = $this->description;

        return $copy;
    }

    /** @return array<string, Type> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * Rules for the top-level input object, whose own key is not a path.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<mixed>>
     */
    public function rootRules(array $input): array
    {
        $rules = [];

        foreach ($this->fields as $key => $type) {
            $type->collectRules(array_key_exists($key, $input) ? $input[$key] : Missing::value(), $key, $rules);
        }

        return $rules;
    }

    /** @return list<mixed> */
    protected function rules(): array
    {
        return ['array'];
    }

    protected function allowsEmpty(): bool
    {
        return true;
    }

    protected function collectChildRules(mixed $value, string $path, array &$rules): void
    {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            return;
        }

        foreach ($this->fields as $key => $type) {
            $type->collectRules(array_key_exists($key, $value) ? $value[$key] : Missing::value(), $path.'.'.$key, $rules);
        }
    }

    protected function cast(mixed $value): mixed
    {
        $out = [];

        foreach ($this->fields as $key => $type) {
            if (array_key_exists($key, $value)) {
                $out[$key] = $type->hydrate($value[$key]);
            }
        }

        return $out;
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        $source = $serializer->source($value, $path, $this);
        $out = [];

        foreach ($this->fields as $key => $type) {
            $item = $serializer->read($source, $key);
            $itemPath = $path === '' ? $key : $path.'.'.$key;

            if ($item instanceof Missing) {
                if ($type->isOptional()) {
                    continue;
                }

                throw new OutputContractViolation($itemPath, $type->label(), 'missing key');
            }

            $out[$key] = $serializer->value($type, $item, $itemPath);
        }

        // An empty PHP array would encode as [], which is not an object.
        return $out === [] ? new \stdClass : $out;
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        if ($this->name !== null) {
            return $writer->interface($this->name, $this, $direction);
        }

        return $this->body($writer, $direction);
    }

    /**
     * The `{ ... }` literal for this object.
     */
    public function body(TypeScriptWriter $writer, Direction $direction, string $indent = ''): string
    {
        if ($this->fields === []) {
            return 'Record<string, never>';
        }

        $lines = [];

        foreach ($this->fields as $key => $type) {
            if ($type->getDescription() !== null) {
                $lines[] = $indent.'  /** '.str_replace('*/', '*\\/', $type->getDescription()).' */';
            }

            $lines[] = $indent.'  '.$key.($type->isOptional() ? '?' : '').': '.$type->nullableTypeScript($writer, $direction).';';
        }

        return "{\n".implode("\n", $lines)."\n".$indent.'}';
    }

    public function label(): string
    {
        return $this->name ?? 'object';
    }
}
