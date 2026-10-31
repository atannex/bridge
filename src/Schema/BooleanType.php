<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;

final class BooleanType extends Type
{
    /** @return list<mixed> */
    protected function rules(): array
    {
        return ['boolean'];
    }

    protected function cast(mixed $value): mixed
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        // TINYINT columns come back as 0/1.
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) (int) $value;
        }

        $this->violation($path, 'boolean', $value);
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        return 'boolean';
    }

    public function label(): string
    {
        return 'boolean';
    }
}
