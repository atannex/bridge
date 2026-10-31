<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A calendar date with no time or zone, "2026-10-30".
 */
final class DateType extends Type
{
    /** @return list<mixed> */
    protected function rules(): array
    {
        return ['string', 'date_format:Y-m-d'];
    }

    protected function cast(mixed $value): mixed
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $value);
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        $this->violation($path, 'date', $value);
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        return 'string';
    }

    public function label(): string
    {
        return 'date';
    }
}
