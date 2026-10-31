<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use BackedEnum;

/**
 * Builds Bridge schemas.
 *
 *     Schema::object([
 *         'id' => Schema::integer(),
 *         'name' => Schema::string()->max(120),
 *         'price' => Schema::decimal(2),
 *         'status' => Schema::enum(OrderStatus::class),
 *         'tags' => Schema::list(Schema::string())->optional(),
 *     ], 'Product');
 */
final class Schema
{
    public static function string(): StringType
    {
        return new StringType;
    }

    public static function integer(): IntegerType
    {
        return new IntegerType;
    }

    /** A float. For money use decimal(). */
    public static function number(): NumberType
    {
        return new NumberType;
    }

    /** An exact decimal carried as a string, e.g. "12.50". */
    public static function decimal(int $scale = 2): DecimalType
    {
        return new DecimalType($scale);
    }

    public static function boolean(): BooleanType
    {
        return new BooleanType;
    }

    /** "2026-10-30" */
    public static function date(): DateType
    {
        return new DateType;
    }

    /** "2026-10-30T14:05:00+00:00" */
    public static function dateTime(): DateTimeType
    {
        return new DateTimeType;
    }

    /**
     * @param  class-string<BackedEnum>|list<string|int>  $enum
     */
    public static function enum(string|array $enum, ?string $name = null): EnumType
    {
        return is_array($enum) ? EnumType::ofValues($enum, $name) : EnumType::ofClass($enum, $name);
    }

    public static function list(Type $items): ArrayType
    {
        return new ArrayType($items);
    }

    /**
     * @param  array<string, Type>  $fields
     */
    public static function object(array $fields, ?string $name = null): ObjectType
    {
        return new ObjectType($fields, $name);
    }

    /** A map with client-chosen keys. */
    public static function record(Type $values): RecordType
    {
        return new RecordType($values);
    }

    public static function file(): FileType
    {
        return new FileType;
    }

    /** Output only: a page from any Laravel paginator. */
    public static function paginated(Type $items): PaginatedType
    {
        return new PaginatedType($items);
    }

    /** Output only: a signed link to a stored file. Return a Download. */
    public static function download(): DownloadType
    {
        return new DownloadType;
    }

    /** Any plain JSON. Generates `unknown`; prefer a real schema. */
    public static function mixed(): MixedType
    {
        return new MixedType;
    }
}
