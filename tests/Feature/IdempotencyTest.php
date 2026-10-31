<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Feature;

use Atannex\Bridge\Context;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Facades\Bridge;
use Atannex\Bridge\Input;
use Atannex\Bridge\Tests\Fixtures\Actions\CreateDocument;
use Atannex\Bridge\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

final class IdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CreateDocument::$runs = 0;
        Sanctum::actingAs($this->user());
    }

    public function test_a_retried_write_with_the_same_key_is_replayed_not_rerun(): void
    {
        $first = $this->call_('documents.create', ['title' => 'Once'], ['Idempotency-Key' => 'key-00000001'])->assertOk();
        $second = $this->call_('documents.create', ['title' => 'Once'], ['Idempotency-Key' => 'key-00000001'])->assertOk();

        $this->assertSame(1, CreateDocument::$runs);
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertTrue($second->json('meta.idempotent_replay'));
        $this->assertNotSame($first->json('meta.request_id'), $second->json('meta.request_id'));
    }

    public function test_the_same_key_with_different_input_is_a_conflict(): void
    {
        $this->call_('documents.create', ['title' => 'One'], ['Idempotency-Key' => 'key-00000002'])->assertOk();

        $this->call_('documents.create', ['title' => 'Two'], ['Idempotency-Key' => 'key-00000002'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, CreateDocument::$runs);
    }

    public function test_keys_are_scoped_to_the_user(): void
    {
        $this->call_('documents.create', ['title' => 'Mine'], ['Idempotency-Key' => 'key-00000003'])->assertOk();

        Sanctum::actingAs($this->user());
        $this->call_('documents.create', ['title' => 'Mine'], ['Idempotency-Key' => 'key-00000003'])
            ->assertOk()
            ->assertJsonMissingPath('meta.idempotent_replay');

        $this->assertSame(2, CreateDocument::$runs);
    }

    public function test_validation_failures_are_stored_and_replayed_too(): void
    {
        $this->call_('documents.create', ['title' => str_repeat('x', 60)], ['Idempotency-Key' => 'key-00000004'])->assertStatus(422);
        $this->call_('documents.create', ['title' => str_repeat('x', 60)], ['Idempotency-Key' => 'key-00000004'])
            ->assertStatus(422)
            ->assertJsonPath('meta.idempotent_replay', true);
    }

    public function test_a_key_still_in_flight_is_refused(): void
    {
        $user = auth()->user();
        $id = 'bridge:idempotency:'.hash('sha256', implode('|', ['documents.create', 'user:'.$user::class.':'.$user->getAuthIdentifier(), '-', 'key-00000005']));
        Cache::lock($id.':lock', 60)->get();

        $this->call_('documents.create', ['title' => 'x'], ['Idempotency-Key' => 'key-00000005'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_IN_PROGRESS');
    }

    public function test_guests_never_share_stored_responses(): void
    {
        Bridge::action('guest.sign', GuestSignup::class, ['access' => 'guest', 'idempotency' => 'idempotent']);
        GuestSignup::$runs = 0;
        $this->app['auth']->forgetGuards(); // signed out: setUp() acted as a user

        $headers = ['Idempotency-Key' => 'shared-key-123'];
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->postJson('/bridge/v1/call/guest.sign', ['input' => (object) []], $headers)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->postJson('/bridge/v1/call/guest.sign', ['input' => (object) []], $headers)->assertJsonPath('meta.idempotent_replay', true);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->postJson('/bridge/v1/call/guest.sign', ['input' => (object) []], $headers)->assertOk()->assertJsonMissingPath('meta.idempotent_replay');

        $this->assertSame(2, GuestSignup::$runs);
    }

    public function test_without_a_key_every_call_runs(): void
    {
        $this->call_('documents.create', ['title' => 'a'])->assertOk();
        $this->call_('documents.create', ['title' => 'a'])->assertOk();

        $this->assertSame(2, CreateDocument::$runs);
    }

    public function test_malformed_keys_are_ignored(): void
    {
        $this->call_('documents.create', ['title' => 'a'], ['Idempotency-Key' => 'short'])->assertOk();
        $this->call_('documents.create', ['title' => 'a'], ['Idempotency-Key' => 'short'])->assertOk()->assertJsonMissingPath('meta.idempotent_replay');
    }
}

final class GuestSignup implements Action
{
    public static int $runs = 0;

    public function handle(Input $input, Context $context): mixed
    {
        self::$runs++;

        return null;
    }
}
