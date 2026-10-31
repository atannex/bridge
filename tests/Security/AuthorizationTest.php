<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Security;

use Atannex\Bridge\Facades\Bridge;
use Atannex\Bridge\Tenancy\Tenant;
use Atannex\Bridge\Tests\Fixtures\Actions\AdminStats;
use Atannex\Bridge\Tests\Fixtures\Actions\Locked;
use Atannex\Bridge\Tests\Fixtures\Document;
use Atannex\Bridge\Tests\TestCase;
use Laravel\Sanctum\Sanctum;

final class AuthorizationTest extends TestCase
{
    public function test_operations_that_need_a_user_answer_401_to_guests(): void
    {
        $this->call_('me.show')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->call_('admin.stats')->assertUnauthorized();
        $this->call_('documents.create', ['title' => 'x'])->assertUnauthorized();
    }

    public function test_401_comes_before_validation_so_guests_learn_nothing_about_inputs(): void
    {
        $this->call_('documents.create', ['title' => str_repeat('x', 500)])
            ->assertUnauthorized();
    }

    public function test_a_gate_ability_refuses_with_403_and_the_action_never_runs(): void
    {
        AdminStats::$runs = 0;
        Sanctum::actingAs($this->user());

        $this->call_('admin.stats')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertSame(0, AdminStats::$runs);

        Sanctum::actingAs($this->user(['is_admin' => true]));
        $this->call_('admin.stats')->assertOk()->assertJsonPath('data.users', 42);
        $this->assertSame(1, AdminStats::$runs);
    }

    public function test_protected_operations_with_no_rule_deny_everyone(): void
    {
        Locked::$runs = 0;
        Sanctum::actingAs($this->user(['is_admin' => true]));

        $this->call_('test.locked')->assertForbidden();
        $this->assertSame(0, Locked::$runs);
    }

    public function test_record_level_authorization_hides_other_users_records_as_404(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $document = Document::query()->create(['user_id' => $alice->id, 'title' => 'Alice private']);

        Sanctum::actingAs($bob);
        $this->call_('documents.show', ['id' => $document->id])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertDontSee('Alice private');

        // Indistinguishable from a document that does not exist.
        $this->call_('documents.show', ['id' => 999])->assertNotFound();

        Sanctum::actingAs($alice);
        $this->call_('documents.show', ['id' => $document->id])->assertOk()->assertJsonPath('data.title', 'Alice private');
    }

    public function test_mass_assignment_through_input_is_impossible(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        Sanctum::actingAs($alice);

        $this->call_('documents.create', ['title' => 'Mine', 'user_id' => $bob->id, 'internal_code' => 'X', 'id' => 777])
            ->assertOk()
            ->assertJsonPath('data.user_id', $alice->id);

        $document = Document::query()->sole();
        $this->assertSame($alice->id, $document->user_id);
        $this->assertNull($document->internal_code);
        $this->assertNotSame(777, $document->id);
    }

    public function test_cross_tenant_selection_is_refused(): void
    {
        $alice = $this->user(['team_id' => 1]);
        Sanctum::actingAs($alice);

        $this->call_('team.info')->assertOk()->assertJsonPath('data.team', '1');
        $this->call_('team.info', [], ['X-Bridge-Tenant' => '1'])->assertOk();

        $this->call_('team.info', [], ['X-Bridge-Tenant' => '2'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'TENANT_FORBIDDEN');

        $this->call_('team.info', [], ['X-Bridge-Tenant' => "1' OR 1=1"])->assertForbidden();
    }

    public function test_a_user_without_a_tenant_gets_tenant_required(): void
    {
        Sanctum::actingAs($this->user());

        $this->call_('team.info')->assertStatus(400)->assertJsonPath('error.code', 'TENANT_REQUIRED');
    }

    public function test_a_resolver_that_answers_with_another_tenant_is_overruled(): void
    {
        Bridge::resolveTenantsUsing(fn () => new Tenant('1'));
        Sanctum::actingAs($this->user(['team_id' => 2]));

        $this->call_('team.info', [], ['X-Bridge-Tenant' => '2'])->assertForbidden();
    }
}
