<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Feature;

use Atannex\Bridge\Events\OperationHandled;
use Atannex\Bridge\Tests\Fixtures\Actions\Nothing;
use Atannex\Bridge\Tests\TestCase;
use Illuminate\Support\Facades\Event;

final class CallTest extends TestCase
{
    public function test_a_registered_operation_runs_and_answers_in_protocol_form(): void
    {
        $response = $this->call_('test.echo', $this->validEcho());

        $response->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.version', '1')
            ->assertJsonPath('meta.operation', 'test.echo')
            ->assertJsonPath('data.name', 'Toghu')
            ->assertJsonPath('data.price', '12.50')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.when', '2026-10-30T13:05:00+00:00')
            ->assertJsonPath('data.day', '2026-10-30')
            ->assertJsonPath('data.address.city', 'Bamenda');

        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('meta.request_id'));
    }

    public function test_input_is_hydrated_to_typed_values(): void
    {
        $received = $this->call_('test.echo', $this->validEcho())->json('data.received');

        $this->assertSame('int', $received['count']);
        $this->assertSame('string', $received['price']);
        $this->assertSame('Atannex\Bridge\Tests\Fixtures\Status', $received['status']);
        $this->assertSame('Carbon\CarbonImmutable', $received['when']);
        $this->assertSame('bool', $received['flag']);
        $this->assertSame('null', $received['note']);
    }

    public function test_unknown_operations_are_404_without_echoing_the_name(): void
    {
        $this->call_('nope.nothing')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonMissingPath('meta.operation');

        $this->postJson('/bridge/v1/call/Bad..Name', [])->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->postJson('/bridge/v1/call/'.str_repeat('a', 130), [])->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_an_action_class_that_was_never_registered_cannot_be_reached(): void
    {
        // The class exists and implements Action, but no name points at it.
        $this->call_('actions.locked')->assertNotFound();
        $this->call_(rawurlencode('Atannex\\Bridge\\Tests\\Fixtures\\Actions\\Boom'))->assertNotFound();
    }

    public function test_validation_failures_return_422_with_dot_path_details(): void
    {
        $input = $this->validEcho();
        $input['count'] = 0;
        $input['tags'] = ['ok', str_repeat('x', 20)];
        $input['address'] = ['zip' => '237'];
        $input['price'] = '12.345';

        $this->call_('test.echo', $input)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['count', 'tags.1', 'address.city', 'price']]]);
    }

    public function test_required_keys_and_null_handling(): void
    {
        $input = $this->validEcho();
        unset($input['name'], $input['note']);
        $input['status'] = null;

        $this->call_('test.echo', $input)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['name', 'note', 'status']]]);
    }

    public function test_children_of_an_absent_optional_object_are_not_required(): void
    {
        $input = $this->validEcho();
        unset($input['address']);

        $this->call_('test.echo', $input)->assertOk()->assertJsonMissingPath('data.address');
    }

    public function test_unknown_input_keys_are_dropped(): void
    {
        $input = $this->validEcho() + ['is_admin' => true, 'role' => 'owner'];
        $input['address']['injected'] = 'x';

        $received = $this->call_('test.echo', $input)->assertOk()->json('data.received');

        $this->assertArrayNotHasKey('is_admin', $received);
        $this->assertArrayNotHasKey('role', $received);
    }

    public function test_paginated_output_for_every_paginator(): void
    {
        foreach (range(1, 5) as $i) {
            $this->user();
        }

        $this->call_('users.list', ['page' => 2])
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.type', 'length_aware')
            ->assertJsonPath('data.pagination.total', 5)
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.last_page', 3)
            ->assertJsonPath('data.pagination.has_more', true);

        $this->call_('users.list', ['mode' => 'simple', 'page' => 3])
            ->assertJsonPath('data.pagination.type', 'simple')
            ->assertJsonPath('data.pagination.has_more', false)
            ->assertJsonMissingPath('data.pagination.total');

        $first = $this->call_('users.list', ['mode' => 'cursor'])->assertJsonPath('data.pagination.type', 'cursor');
        $next = $first->json('data.pagination.next_cursor');
        $this->assertIsString($next);

        $this->call_('users.list', ['mode' => 'cursor', 'cursor' => $next])
            ->assertJsonPath('data.items.0.id', 3);
    }

    public function test_an_operation_without_output_returns_null_data(): void
    {
        Nothing::$value = null;

        $json = $this->call_('test.void')->assertOk()->json();

        $this->assertArrayHasKey('data', $json);
        $this->assertNull($json['data']);
    }

    public function test_conflicts_carry_the_action_message_and_details(): void
    {
        $this->call_('test.conflict')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT')
            ->assertJsonPath('error.message', 'The cart is empty.')
            ->assertJsonPath('error.details.reason', 'empty_cart');
    }

    public function test_deprecated_operations_say_so(): void
    {
        $this->call_('test.old')
            ->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertJsonPath('meta.deprecated.message', 'Use test.echo instead.');
    }

    public function test_protocol_version_is_checked(): void
    {
        $this->call_('test.void', [], ['X-Bridge-Version' => '1'])->assertOk();

        $this->call_('test.void', [], ['X-Bridge-Version' => '2'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'UNSUPPORTED_VERSION')
            ->assertJsonPath('error.details.supported', ['1']);
    }

    public function test_request_and_correlation_ids_are_kept_only_when_well_formed(): void
    {
        $this->call_('test.void', [], ['X-Request-Id' => 'client-req-0001', 'X-Correlation-Id' => 'flow-12345678'])
            ->assertHeader('X-Request-Id', 'client-req-0001')
            ->assertHeader('X-Correlation-Id', 'flow-12345678')
            ->assertJsonPath('meta.request_id', 'client-req-0001');

        $response = $this->call_('test.void', [], ['X-Request-Id' => "bad\nid <script>"]);
        $this->assertNotSame("bad\nid <script>", $response->json('meta.request_id'));
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $response->json('meta.request_id'));
    }

    public function test_health_reveals_nothing(): void
    {
        $response = $this->getJson('/bridge/v1/health')->assertOk();

        $this->assertSame(['status' => 'ok'], $response->json('data'));
        $this->assertSame(['request_id', 'version'], array_keys($response->json('meta')));
    }

    public function test_every_call_fires_an_observability_event_without_payloads(): void
    {
        Event::fake([OperationHandled::class]);

        $this->call_('test.conflict');

        Event::assertDispatched(OperationHandled::class, function (OperationHandled $event) {
            return $event->operation === 'test.conflict'
                && $event->status === 409
                && $event->errorCode === 'CONFLICT'
                && $event->durationMs >= 0
                && ! array_key_exists('input', $event->toLogContext());
        });
    }

    public function test_rate_limits_answer_429_with_retry_after(): void
    {
        $this->call_('test.limited')->assertOk();
        $this->call_('test.limited')->assertOk();

        $this->call_('test.limited')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonStructure(['error' => ['details' => ['retry_after']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validEcho(): array
    {
        return [
            'name' => 'Toghu',
            'count' => 3,
            'price' => '12.5',
            'status' => 'published',
            'tags' => ['red', 'gold'],
            'address' => ['city' => 'Bamenda'],
            'when' => '2026-10-30T14:05:00+01:00',
            'day' => '2026-10-30',
            'scores' => ['a' => 1],
            'flag' => true,
            'note' => null,
        ];
    }
}
