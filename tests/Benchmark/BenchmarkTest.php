<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Benchmark;

use Atannex\Bridge\BridgeServiceProvider;
use Atannex\Bridge\Registry\Registry;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Serialization\Serializer;
use Atannex\Bridge\Tests\Fixtures\Actions\EchoInput;
use Atannex\Bridge\Tests\TestCase;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;

/**
 * Rough, in-process timings. Run with `composer bench`; excluded from the
 * normal suite. Numbers vary by machine: compare runs, not absolutes.
 */
#[Group('benchmark')]
final class BenchmarkTest extends TestCase
{
    public function test_benchmarks(): void
    {
        $results = [];
        $registry = app(Registry::class);
        $registry->operations();

        $results['registry lookup (hit)'] = $this->time(100_000, fn () => $registry->find('users.show'));
        $results['registry lookup (miss)'] = $this->time(100_000, fn () => $registry->find('nope.nope'));

        $product = Schema::object([
            'id' => Schema::integer(), 'name' => Schema::string(), 'price' => Schema::decimal(2),
            'created_at' => Schema::dateTime(), 'tags' => Schema::list(Schema::string()),
        ]);
        $rows = array_map(fn (int $i) => ['id' => $i, 'name' => "Item {$i}", 'price' => '12.5', 'created_at' => CarbonImmutable::now(), 'tags' => ['a', 'b']], range(1, 100));
        $serializer = new Serializer('UTC');
        $results['serialize 100 objects'] = $this->time(200, fn () => $serializer->serialize(Schema::list($product), $rows));
        $plain = array_map(fn (array $row) => ['created_at' => $row['created_at']->toAtomString()] + $row, $rows);
        $results['json_encode 100 plain rows (baseline)'] = $this->time(200, fn () => json_encode($plain));

        $input = ['name' => 'Toghu', 'count' => 3, 'price' => '12.5', 'status' => 'published', 'tags' => ['red', 'gold'],
            'address' => ['city' => 'Bamenda'], 'when' => '2026-10-30T14:05:00+01:00', 'note' => null];
        $schema = (new EchoInput)->input();
        $validator = app('validator');
        $results['validate + hydrate echo input'] = $this->time(2_000, function () use ($schema, $input, $validator) {
            $validator->make($input, $schema->rootRules($input))->validate();
            $schema->hydrate($input);
        });

        // The default limiter (120/min) would turn most iterations into fast 429s.
        config(['bridge.rate_limit' => null]);
        $this->call_('test.void')->assertOk();
        $this->call_('test.echo', $input + ['day' => '2026-10-30'])->assertOk();
        $results['HTTP call, no input (in-process kernel)'] = $this->time(300, fn () => $this->call_('test.void')->assertOk());
        $results['HTTP call, validated echo (in-process kernel)'] = $this->time(300, fn () => $this->call_('test.echo', $input + ['day' => '2026-10-30'])->assertOk());
        $results['HTTP health, no Bridge pipeline (baseline)'] = $this->time(300, fn () => $this->getJson('/bridge/v1/health'));

        $results['package boot (provider register+boot)'] = $this->time(200, function () {
            $app = $this->createApplication();
            $app->register(BridgeServiceProvider::class, true);
        }) - $this->time(200, fn () => $this->createApplication());

        $lines = ['', str_pad('Benchmark', 48).'  mean'];

        foreach ($results as $name => $micro) {
            $lines[] = str_pad($name, 48).'  '.number_format($micro, 2).' µs';
        }

        fwrite(STDERR, implode(PHP_EOL, $lines).PHP_EOL.'PHP '.PHP_VERSION.', Laravel '.app()->version().', opcache '.(function_exists('opcache_get_status') && @opcache_get_status() ? 'on' : 'off').PHP_EOL);
        $this->assertNotEmpty($results);
    }

    /** Mean microseconds per call. */
    private function time(int $iterations, callable $callback): float
    {
        $callback();
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $callback();
        }

        return (hrtime(true) - $start) / 1e3 / $iterations;
    }
}
