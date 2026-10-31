<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Security;

use Atannex\Bridge\Context;
use Atannex\Bridge\Exceptions\OutputContractViolation;
use Atannex\Bridge\Schema\Schema;
use Atannex\Bridge\Serialization\Serializer;
use Atannex\Bridge\Tests\Fixtures\Actions\Me;
use Atannex\Bridge\Tests\Fixtures\Actions\Nothing;
use Atannex\Bridge\Tests\Fixtures\User;
use Atannex\Bridge\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\Sanctum;

final class ExposureTest extends TestCase
{
    public function test_only_schema_fields_of_a_model_are_sent(): void
    {
        $user = $this->user(['secret_note' => 'never send this']);

        $response = $this->call_('users.show', ['id' => $user->id])->assertOk();

        $this->assertSame(['id', 'name', 'is_admin', 'created_at'], array_keys($response->json('data')));
        $response->assertDontSee('never send this')->assertDontSee('password')->assertDontSee($user->email);
    }

    public function test_internal_errors_never_leak_messages_paths_or_classes(): void
    {
        Exceptions::fake();

        $response = $this->call_('test.boom')->assertStatus(500);

        $response->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('secret_table')
            ->assertDontSee('/var/www')
            ->assertDontSee('RuntimeException');

        $this->assertSame(['code', 'message'], array_keys($response->json('error')));
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_debug_mode_does_not_change_that(): void
    {
        config(['app.debug' => true]);

        $this->call_('test.boom')->assertStatus(500)->assertDontSee('SQLSTATE')->assertJsonMissingPath('exception');
    }

    public function test_output_that_breaks_its_schema_is_refused_not_sent(): void
    {
        Exceptions::fake();
        Sanctum::actingAs($this->user(['secret_note' => 'leak?']));

        $this->call_('test.leaky')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertDontSee('leak?');

        Exceptions::assertReported(fn (OutputContractViolation $e) => str_contains($e->getMessage(), '[test.leaky]')
            && str_contains($e->getMessage(), 'nickname'));
    }

    public function test_an_action_without_output_cannot_return_data(): void
    {
        Exceptions::fake();
        Nothing::$value = ['password' => 'secret'];

        $this->call_('test.void')->assertStatus(500)->assertDontSee('secret');

        Nothing::$value = null;
    }

    public function test_output_never_calls_model_methods_that_are_not_typed_relations(): void
    {
        $model = new class extends Model
        {
            public static int $published = 0;

            protected $guarded = [];

            public function publish(): bool
            {
                self::$published++;

                return true;
            }

            public function owner(): BelongsTo
            {
                return $this->belongsTo(User::class, 'user_id');
            }
        };

        $instance = $model->newInstance(['id' => 1, 'user_id' => null]);
        $serializer = new Serializer('UTC');

        try {
            $serializer->serialize(Schema::object(['publish' => Schema::boolean()]), $instance);
            $this->fail('An untyped method must be treated as a missing key.');
        } catch (OutputContractViolation) {
            $this->assertSame(0, $model::$published);
        }

        // A typed relation is still read (here it resolves to null).
        $this->assertSame(['owner' => null], $serializer->serialize(Schema::object([
            'owner' => Schema::object(['id' => Schema::integer()])->nullable(),
        ]), $instance));
    }

    public function test_mixed_output_rejects_objects(): void
    {
        $serializer = new Serializer('UTC');

        $this->expectException(OutputContractViolation::class);
        $serializer->serialize(Schema::mixed(), ['user' => $this->user()]);
    }

    public function test_malformed_bodies_are_400(): void
    {
        $cases = [
            '[1,2]',
            '"text"',
            '{"input": [1,2]}',
            '{"input": "x"}',
            '{"input": {}, "extra": 1}',
            '{not json',
            '{"input": '.str_repeat('[', 70).str_repeat(']', 70).'}',
        ];

        foreach ($cases as $body) {
            $this->call('POST', '/bridge/v1/call/test.void', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'MALFORMED_REQUEST');
        }
    }

    public function test_an_empty_body_is_an_empty_input(): void
    {
        $this->call('POST', '/bridge/v1/call/test.void', [], [], [], ['CONTENT_TYPE' => 'application/json'], '')->assertOk();
    }

    public function test_other_content_types_are_415(): void
    {
        $this->call('POST', '/bridge/v1/call/test.void', [], [], [], ['CONTENT_TYPE' => 'text/xml', 'HTTP_ACCEPT' => 'application/json'], '<input/>')
            ->assertStatus(415)
            ->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');
    }

    public function test_oversized_bodies_are_413(): void
    {
        config(['bridge.limits.json_bytes' => 100]);

        $this->call_('test.echo', ['name' => str_repeat('x', 200)])
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE')
            ->assertJsonPath('error.details.limit_bytes', 100);
    }

    public function test_huge_lists_are_capped_before_rules_are_expanded(): void
    {
        $input = ['name' => 'x', 'count' => 1, 'price' => '1', 'status' => 'draft', 'note' => null, 'tags' => array_fill(0, 50, 'a')];

        $this->call_('test.echo', $input)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['tags']]]);
    }

    public function test_https_is_enforced_when_configured(): void
    {
        config(['bridge.require_https' => true]);

        $this->call_('test.void')->assertForbidden()->assertJsonPath('error.code', 'HTTPS_REQUIRED');
        $this->postJson('https://localhost/bridge/v1/call/test.void', ['input' => (object) []])->assertOk();
    }

    public function test_csrf_failures_on_session_routes_answer_in_protocol_form(): void
    {
        $this->app['router']->post('bridge/v1/__csrf_probe', fn () => 'never')
            ->middleware([StartSession::class, VerifyCsrfToken::class]);

        // Testbench disables CSRF in unit tests; run the real middleware.
        $this->app->instance('env', 'local');

        $this->withSession(['_token' => 'right'])
            ->postJson('/bridge/v1/__csrf_probe', [], ['X-CSRF-TOKEN' => 'wrong'])
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'CSRF_MISMATCH');
    }

    public function test_errors_raised_before_bridge_runs_still_answer_in_protocol_form(): void
    {
        // Maintenance mode, a gateway or any middleware aborting with 503.
        $this->app['router']->get('bridge/v1/__down', fn () => abort(503, 'Down for an upgrade of db-7', ['Retry-After' => '30']));

        $this->getJson('/bridge/v1/__down')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')
            ->assertDontSee('db-7');

        $this->getJson('/bridge/v1/no/such/route')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_context_does_not_leak_between_requests_in_one_worker(): void
    {
        config(['bridge.locales' => ['en', 'fr']]);
        $alice = $this->user();
        $bob = $this->user();

        Sanctum::actingAs($alice);
        $first = $this->call_('me.show', [], ['Accept-Language' => 'fr', 'X-Bridge-Client' => 'mobile/1.0'])->assertOk();
        $this->assertSame('fr', $first->json('data.locale'));
        $this->assertSame('en', app()->getLocale(), 'The locale must be restored after the call.');

        Sanctum::actingAs($bob);
        $second = $this->call_('me.show')->assertOk();

        $this->assertSame($bob->id, $second->json('data.id'));
        $this->assertSame('en', $second->json('data.locale'));
        $this->assertNull($second->json('data.client'));
        $this->assertNotSame($first->json('data.request_id'), $second->json('data.request_id'));
        $this->assertFalse(app()->bound(Context::class), 'Context must never be bound in the container.');
        $this->assertTrue(class_exists(Me::class));
    }
}
