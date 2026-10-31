<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Unit;

use Atannex\Bridge\Exceptions\DuplicateOperation;
use Atannex\Bridge\Exceptions\InvalidOperation;
use Atannex\Bridge\Facades\Bridge;
use Atannex\Bridge\Registry\Discovery;
use Atannex\Bridge\Registry\Registry;
use Atannex\Bridge\Tests\Fixtures\Actions\Boom;
use Atannex\Bridge\Tests\Fixtures\Actions\Me;
use Atannex\Bridge\Tests\Fixtures\Discovered\Ping;
use Atannex\Bridge\Tests\TestCase;

final class RegistryTest extends TestCase
{
    public function test_duplicate_names_fail_with_both_classes_named(): void
    {
        $this->expectException(DuplicateOperation::class);
        $this->expectExceptionMessage(Me::class);

        Bridge::action('test.boom', Me::class);
    }

    public function test_bad_names_and_non_actions_are_refused(): void
    {
        $registry = $this->fresh();
        $registry->action('Bad Name', Boom::class);

        try {
            $registry->operations();
            $this->fail('Expected InvalidOperation.');
        } catch (InvalidOperation $e) {
            $this->assertStringContainsString('Bad Name', $e->getMessage());
        }

        $registry = $this->fresh();
        $registry->action('x.y', \stdClass::class);
        $this->expectException(InvalidOperation::class);
        $registry->operations();
    }

    public function test_unknown_options_are_an_error_not_silently_ignored(): void
    {
        $registry = $this->fresh();
        $registry->action('x.y', Boom::class, ['acess' => 'guest']);

        $this->expectExceptionMessage('acess');
        $registry->operations();
    }

    public function test_discovery_finds_only_attributed_classes_in_listed_namespaces(): void
    {
        $registry = $this->fresh(['Atannex\\Bridge\\Tests\\Fixtures\\Discovered' => __DIR__.'/../Fixtures/Discovered']);

        $this->assertSame(['system.ping'], array_keys($registry->operations()));
        $this->assertSame('Says pong.', $registry->find('system.ping')->description);
    }

    public function test_the_compiled_cache_round_trips_and_skips_discovery(): void
    {
        $path = sys_get_temp_dir().'/bridge-cache-'.uniqid().'.php';
        $namespaces = ['Atannex\\Bridge\\Tests\\Fixtures\\Discovered' => __DIR__.'/../Fixtures/Discovered'];

        $registry = new Registry(new Discovery, $namespaces, $path);
        $registry->action('me.show', Me::class, ['access' => 'authenticated']);
        $this->assertSame(2, $registry->cache());

        // Discovery here would find nothing: the operation can only come from the cache.
        $cached = new Registry(new Discovery, ['Nope' => '/does/not/exist'], $path);
        $cached->action('me.show', Me::class, ['access' => 'authenticated']);

        $this->assertSame(['me.show', 'system.ping'], array_keys($cached->operations()));
        $this->assertEquals($registry->find('system.ping'), $cached->find('system.ping'));
        $this->assertSame(Ping::class, $cached->find('system.ping')->class);

        unlink($path);
    }

    public function test_lookups_are_lazy(): void
    {
        $registry = $this->fresh();
        // A broken registration costs nothing until the registry is read.
        $registry->action('lazy.one', 'Does\\Not\\Exist');
        $this->assertTrue(true);

        $this->expectException(InvalidOperation::class);
        $registry->find('lazy.one');
    }

    private function fresh(array $namespaces = []): Registry
    {
        return new Registry(new Discovery, $namespaces, sys_get_temp_dir().'/bridge-none-'.uniqid().'.php');
    }
}
