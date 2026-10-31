<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * A page of items from any Laravel paginator (output only), sent as
 * `{ items, pagination }` with a `type` the client can switch on.
 */
final class PaginatedType extends Type
{
    public function __construct(private readonly Type $items) {}

    public function items(): Type
    {
        return $this->items;
    }

    /** @return list<mixed> */
    protected function rules(): array
    {
        return ['prohibited'];
    }

    protected function cast(mixed $value): mixed
    {
        return $value;
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        $items = fn (iterable $items) => array_map(
            fn (mixed $item, int $index) => $serializer->value($this->items, $item, $path.'.items.'.$index),
            $list = is_array($items) ? array_values($items) : iterator_to_array($items, false),
            array_keys($list),
        );

        if ($value instanceof CursorPaginator) {
            return [
                'items' => $items($value->items()),
                'pagination' => [
                    'type' => 'cursor',
                    'per_page' => $value->perPage(),
                    'has_more' => $value->hasMorePages(),
                    'next_cursor' => $value->nextCursor()?->encode(),
                    'prev_cursor' => $value->previousCursor()?->encode(),
                ],
            ];
        }

        if ($value instanceof LengthAwarePaginator) {
            return [
                'items' => $items($value->items()),
                'pagination' => [
                    'type' => 'length_aware',
                    'per_page' => $value->perPage(),
                    'current_page' => $value->currentPage(),
                    'has_more' => $value->hasMorePages(),
                    'total' => $value->total(),
                    'last_page' => $value->lastPage(),
                ],
            ];
        }

        if ($value instanceof Paginator) {
            return [
                'items' => $items($value->items()),
                'pagination' => [
                    'type' => 'simple',
                    'per_page' => $value->perPage(),
                    'current_page' => $value->currentPage(),
                    'has_more' => $value->hasMorePages(),
                ],
            ];
        }

        $this->violation($path, 'paginator', $value);
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        if ($direction === Direction::Input) {
            $writer->error('Schema::paginated() is output only.');

            return 'never';
        }

        return $writer->import('Paginated').'<'.$this->items->nullableTypeScript($writer, $direction).'>';
    }

    public function label(): string
    {
        return 'paginated<'.$this->items->label().'>';
    }
}
