<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;

/**
 * An exact decimal, carried as a string ("12.50") so JavaScript never rounds
 * it through a float.
 */
final class DecimalType extends Type
{
    public function __construct(private readonly int $scale = 2)
    {
        if ($scale < 0 || $scale > 18) {
            throw new \InvalidArgumentException('A decimal scale must be between 0 and 18.');
        }
    }

    /** @return list<mixed> */
    protected function rules(): array
    {
        return ['numeric', 'decimal:0,'.$this->scale];
    }

    protected function cast(mixed $value): mixed
    {
        return $this->format((string) $value);
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        if (is_int($value) || (is_string($value) && is_numeric($value) && ! str_contains(strtolower($value), 'e'))) {
            return $this->format((string) $value);
        }

        if (is_float($value) && is_finite($value)) {
            return number_format($value, $this->scale, '.', '');
        }

        if ($value instanceof \Stringable && is_numeric((string) $value)) {
            return $this->format((string) $value);
        }

        $this->violation($path, 'decimal', $value);
    }

    /**
     * Pad or round a numeric string to the scale without going through float.
     */
    private function format(string $number): string
    {
        if (function_exists('bcadd')) {
            // bcadd truncates; add half a unit in the last place to round.
            $half = $this->scale === 0 ? '0.5' : '0.'.str_repeat('0', $this->scale).'5';
            $rounded = str_starts_with($number, '-') ? bcsub($number, $half, $this->scale) : bcadd($number, $half, $this->scale);

            return $rounded === '-'.number_format(0, $this->scale, '.', '') ? number_format(0, $this->scale, '.', '') : $rounded;
        }

        return number_format((float) $number, $this->scale, '.', '');
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        return 'string';
    }

    public function label(): string
    {
        return 'decimal('.$this->scale.')';
    }
}
