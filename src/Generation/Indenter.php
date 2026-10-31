<?php

declare(strict_types=1);

namespace Atannex\Bridge\Generation;

/**
 * Re-indents generated TypeScript by brace depth, ignoring braces inside
 * string literals, so nested schemas come out readable whatever their depth.
 */
final class Indenter
{
    public static function indent(string $source, string $unit = '  '): string
    {
        $depth = 0;
        $out = [];

        foreach (explode("\n", $source) as $line) {
            $line = trim($line);

            if ($line === '') {
                $out[] = '';

                continue;
            }

            [$opens, $closes, $leadingCloses] = self::count($line);
            $level = max(0, $depth - $leadingCloses);
            // A doc-comment continuation line gets one extra space.
            $prefix = str_starts_with($line, '*') ? ' ' : '';
            $out[] = str_repeat($unit, $level).$prefix.$line;
            $depth = max(0, $depth + $opens - $closes);
        }

        return implode("\n", $out);
    }

    /**
     * @return array{int, int, int}
     */
    private static function count(string $line): array
    {
        $opens = $closes = $leading = 0;
        $quote = null;
        $seenOther = false;

        for ($i = 0, $length = strlen($line); $i < $length; $i++) {
            $char = $line[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $seenOther = true;
            } elseif ($char === '{') {
                $opens++;
                $seenOther = true;
            } elseif ($char === '}') {
                $closes++;

                if (! $seenOther) {
                    $leading++;
                }
            } elseif ($char !== ' ') {
                $seenOther = true;
            }
        }

        return [$opens, $closes, $leading];
    }
}
