<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;

/**
 * Any JSON value. An escape hatch: it generates `unknown` with a warning,
 * and in output only plain JSON data (no objects) passes, so a model cannot be
 * dumped through it by accident.
 */
final class MixedType extends Type
{
    /** @return list<mixed> */
    protected function rules(): array
    {
        return [];
    }

    protected function presenceRules(bool $item): array
    {
        return $this->optional ? ['sometimes', 'nullable'] : ['present', 'nullable'];
    }

    protected function cast(mixed $value): mixed
    {
        return $value;
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        if ($value === null || is_scalar($value)) {
            if (is_float($value) && ! is_finite($value)) {
                $this->violation($path, 'JSON value', $value);
            }

            return $value;
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $out[$key] = $this->serialize($item, $serializer, $path.'.'.$key);
            }

            return $out;
        }

        $this->violation($path, 'plain JSON data (arrays and scalars only)', $value);
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        $writer->warning('Schema::mixed() generates `unknown`; give it a real schema to type it.');

        return 'unknown';
    }

    public function label(): string
    {
        return 'mixed';
    }
}
