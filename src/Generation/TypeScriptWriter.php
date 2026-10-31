<?php

declare(strict_types=1);

namespace Atannex\Bridge\Generation;

use Atannex\Bridge\Schema\Direction;
use Atannex\Bridge\Schema\ObjectType;

/**
 * Collects the named declarations, imports and diagnostics while schemas
 * render themselves as TypeScript.
 */
final class TypeScriptWriter
{
    /** @var array<string, string> name => declaration body */
    private array $declarations = [];

    /** @var array<string, 'interface'|'type'> */
    private array $kinds = [];

    /** @var array<string, Direction|null> which direction first claimed each name */
    private array $directions = [];

    /** @var array<string, true> */
    private array $imports = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    private string $location = '';

    /** Where diagnostics are reported from, e.g. "products.list output". */
    public function at(string $location): void
    {
        $this->location = $location;
    }

    public function import(string $name): string
    {
        $this->imports[$name] = true;

        return $name;
    }

    /**
     * Declare a named interface and return the name to reference.
     *
     * The same name can render differently as input and output (a file field,
     * say); then the second form gets an `Input`/`Output` suffix. Two genuinely
     * different schemas sharing a name is an error.
     */
    public function interface(string $name, ObjectType $object, Direction $direction): string
    {
        return $this->declare($name, 'interface', fn () => $object->body($this, $direction), $direction);
    }

    public function alias(string $name, string $type, object $source): string
    {
        return $this->declare($name, 'type', fn () => $type, null);
    }

    public function error(string $message): void
    {
        $this->errors[] = $this->prefix().$message;
    }

    public function warning(string $message): void
    {
        $this->warnings[] = $this->prefix().$message;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return array_values(array_unique($this->errors));
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /** @return list<string> */
    public function imports(): array
    {
        $imports = array_keys($this->imports);
        sort($imports);

        return $imports;
    }

    /**
     * @return array<string, array{kind: 'interface'|'type', body: string}>
     */
    public function declarations(): array
    {
        $out = [];

        foreach ($this->declarations as $name => $body) {
            $out[$name] = ['kind' => $this->kinds[$name], 'body' => $body];
        }

        ksort($out);

        return $out;
    }

    public static function quote(string $value): string
    {
        return "'".str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", '\\n', '\\r'], $value)."'";
    }

    /**
     * @param  'interface'|'type'  $kind
     */
    private function declare(string $name, string $kind, \Closure $render, ?Direction $direction): string
    {
        $body = $render();

        if (! isset($this->declarations[$name])) {
            return $this->claim($name, $kind, $body, $direction);
        }

        if ($this->declarations[$name] === $body && $this->kinds[$name] === $kind) {
            return $name;
        }

        // The same schema can render differently going in than coming out (a
        // file field, say): the other direction gets a suffixed name. Two
        // different schemas in the same direction are a real clash.
        if ($direction !== null && $this->directions[$name] !== null && $this->directions[$name] !== $direction) {
            $suffixed = $name.ucfirst($direction->value);

            if (! isset($this->declarations[$suffixed])) {
                return $this->claim($suffixed, $kind, $body, $direction);
            }

            if ($this->declarations[$suffixed] === $body) {
                return $suffixed;
            }
        }

        $this->error("Two different schemas are both named [{$name}]. Give one of them another name.");

        return $name;
    }

    /**
     * @param  'interface'|'type'  $kind
     */
    private function claim(string $name, string $kind, string $body, ?Direction $direction): string
    {
        $this->declarations[$name] = $body;
        $this->kinds[$name] = $kind;
        $this->directions[$name] = $direction;

        return $name;
    }

    private function prefix(): string
    {
        return $this->location === '' ? '' : "[{$this->location}] ";
    }
}
