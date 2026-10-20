<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAccount;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_grants_nobody_and_bootstrap_dry_run_changes_nothing(): void
    {
        $user = User::factory()->create(['email' => 'owner@novinpay.test']);
        config(['admin.email' => $user->email]);
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->artisan('admin:bootstrap', ['user' => $user->id])->assertSuccessful();
        $this->assertDatabaseCount('admin_accounts', 0);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_explicit_bootstrap_preserves_credentials_and_is_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'owner@novinpay.test']);
        config(['admin.email' => $user->email]);
        $before = $user->fresh()->getAttributes();
        $this->artisan('admin:bootstrap', ['user' => $user->id, '--apply' => true])->assertSuccessful();
        $this->artisan('admin:bootstrap', ['user' => $user->id, '--apply' => true])->assertSuccessful();
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_accounts', 1);
        $this->assertDatabaseCount('admin_activities', 1);
        $this->assertTrue(AdminAccount::query()->sole()->isSuperAdmin());
        config(['admin.email' => 'unrelated@novinpay.test']);
        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_wrong_identity_missing_config_and_owner_reassignment_are_refused(): void
    {
        $owner = User::factory()->superAdmin()->create(['email' => 'owner@novinpay.test']);
        $other = User::factory()->create(['email' => 'other@novinpay.test']);
        config(['admin.email' => $owner->email]);
        $this->artisan('admin:bootstrap', ['user' => $other->id, '--apply' => true])->assertFailed();
        config(['admin.email' => null]);
        $this->artisan('admin:bootstrap', ['user' => $owner->id, '--apply' => true])->assertFailed();
        config(['admin.email' => $other->email]);
        $this->artisan('admin:bootstrap', ['user' => $other->id, '--apply' => true])->assertFailed();
        $this->assertDatabaseCount('admin_accounts', 1);
        $this->assertSame($owner->id, AdminAccount::query()->sole()->user_id);
    }

    public function test_provisioning_seeder_cannot_overwrite_existing_owner_identity_or_password(): void
    {
        $owner = User::factory()->superAdmin()->create(['email' => 'owner@novinpay.test']);
        $before = $owner->fresh()->getAttributes();
        (new AdminUserSeeder)->provision($owner->email, 'Different-Password-2026');
        $this->assertSame($before, $owner->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('admin_activities', 0);
    }

    public function test_database_unique_slot_prevents_a_second_protected_super_admin(): void
    {
        User::factory()->superAdmin()->create();
        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->superAdmin()->create();
    }
}
