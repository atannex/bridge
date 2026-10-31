<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * An instant, sent as ISO 8601 with an explicit offset
 * ("2026-10-30T14:05:00+00:00") in the configured output timezone.
 */
final class DateTimeType extends Type
{
    /** ISO 8601 forms accepted on input, including JavaScript's toISOString(). */
    private const FORMATS = [
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s.vP',
        'Y-m-d\TH:i:s.uP',
        'Y-m-d\TH:i:s\Z',
        'Y-m-d\TH:i:s.v\Z',
        'Y-m-d\TH:i:s.u\Z',
    ];

    /** @return list<mixed> */
    protected function rules(): array
    {
        return ['string', 'date_format:'.implode(',', self::FORMATS)];
    }

    protected function cast(mixed $value): mixed
    {
        return CarbonImmutable::parse($value)->utc();
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        if (is_string($value) && $value !== '') {
            try {
                $value = CarbonImmutable::parse($value);
            } catch (\Throwable) {
                $this->violation($path, 'date-time', $value);
            }
        }

        if (! $value instanceof DateTimeInterface) {
            $this->violation($path, 'date-time', $value);
        }

        return CarbonImmutable::instance($value)
            ->setTimezone($serializer->timezone())
            ->format(DateTimeInterface::ATOM);
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        return 'string';
    }

    public function label(): string
    {
        return 'date-time';
    }
}
