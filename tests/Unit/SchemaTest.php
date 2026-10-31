<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Unit;

use Atannex\Bridge\Exceptions\OutputContractViolation;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Serialization\Serializer;
use Atannex\Bridge\Tests\Fixtures\Status;
use Atannex\Bridge\Tests\TestCase;
use Carbon\CarbonImmutable;

final class SchemaTest extends TestCase
{
    public function test_rules_expand_against_the_actual_input(): void
    {
        $schema = Schema::object([
            'items' => Schema::list(Schema::object(['qty' => Schema::integer()->min(1)])),
            'address' => Schema::object(['city' => Schema::string()])->optional(),
        ]);

        $rules = $schema->rootRules(['items' => [['qty' => 1], ['qty' => 2]]]);

        $this->assertSame(['present', 'array', 'list', 'max:1000'], $rules['items']);
        $this->assertSame(['required', 'integer', 'min:1'], $rules['items.1.qty']);
        $this->assertSame(['sometimes', 'present', 'array'], $rules['address']);
        $this->assertArrayNotHasKey('address.city', $rules, 'Children of an absent object are not checked.');
        $this->assertArrayNotHasKey('items.*.qty', $rules);
    }

    public function test_nullable_object_children_are_skipped_when_null(): void
    {
        $schema = Schema::object(['address' => Schema::object(['city' => Schema::string()])->nullable()]);

        $this->assertArrayNotHasKey('address.city', $schema->rootRules(['address' => null]));
        $this->assertArrayHasKey('address.city', $schema->rootRules(['address' => ['x' => 1]]));
    }

    public function test_hydration_casts_and_drops_unknown_keys(): void
    {
        $schema = Schema::object([
            'n' => Schema::integer(),
            'd' => Schema::decimal(2),
            'e' => Schema::enum(Status::class),
            'at' => Schema::dateTime(),
            'nested' => Schema::object(['keep' => Schema::boolean()]),
        ]);

        $out = $schema->hydrate(['n' => '5', 'd' => 3, 'e' => 'draft', 'at' => '2026-10-30T10:00:00.000Z', 'nested' => ['keep' => '1', 'drop' => 1], 'extra' => 1]);

        $this->assertSame(5, $out['n']);
        $this->assertSame('3.00', $out['d']);
        $this->assertSame(Status::Draft, $out['e']);
        $this->assertInstanceOf(CarbonImmutable::class, $out['at']);
        $this->assertSame(['keep' => true], $out['nested']);
        $this->assertArrayNotHasKey('extra', $out);
    }

    public function test_output_normalisation(): void
    {
        $s = new Serializer('Africa/Douala');

        $this->assertSame('12.35', $s->serialize(Schema::decimal(2), '12.345'));
        $this->assertSame('-12.35', $s->serialize(Schema::decimal(2), '-12.345'));
        $this->assertSame('0.00', $s->serialize(Schema::decimal(2), '-0.001'));
        $this->assertSame('100000000000000000.10', $s->serialize(Schema::decimal(2), '100000000000000000.1'));
        $this->assertSame(7, $s->serialize(Schema::integer(), '7'));
        $this->assertTrue($s->serialize(Schema::boolean(), 1));
        $this->assertSame('2026-10-30T15:00:00+01:00', $s->serialize(Schema::dateTime(), CarbonImmutable::parse('2026-10-30T14:00:00Z')));
        $this->assertSame('published', $s->serialize(Schema::enum(Status::class), Status::Published));
        $this->assertEquals(new \stdClass, $s->serialize(Schema::object([]), []));
        $this->assertSame(['a' => 1], $s->serialize(Schema::record(Schema::integer()), collect(['a' => 1])));
    }

    public function test_output_violations_name_the_path(): void
    {
        $s = new Serializer('UTC');
        $schema = Schema::object(['items' => Schema::list(Schema::object(['id' => Schema::integer()]))]);

        try {
            $s->serialize($schema, ['items' => [['id' => 1], ['id' => 'x']]]);
            $this->fail('Expected a violation.');
        } catch (OutputContractViolation $e) {
            $this->assertSame('items.1.id', $e->path);
        }

        $this->expectException(OutputContractViolation::class);
        $s->serialize(Schema::enum(Status::class), 'archived');
    }

    public function test_public_properties_only_are_read_from_objects(): void
    {
        $object = new class
        {
            public int $id = 1;

            private string $secret = 'x';
        };

        $s = new Serializer('UTC');
        $this->assertSame(['id' => 1], $s->serialize(Schema::object(['id' => Schema::integer()]), $object));

        $this->expectException(OutputContractViolation::class);
        $s->serialize(Schema::object(['secret' => Schema::string()]), $object);
    }

    public function test_invalid_schema_definitions_fail_fast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Schema::object(['bad-key' => Schema::string()]);
    }
}
