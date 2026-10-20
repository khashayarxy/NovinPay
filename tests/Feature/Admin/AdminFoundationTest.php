<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminAction;
use App\Models\AdminAccount;
use App\Models\AdminActivity;
use App\Models\Product;
use App\Models\RepairRequest;
use App\Models\User;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class AdminFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_PASSWORD = 'Owner-Testing-2026';

    private const STAFF_PASSWORD = 'Staff-Initial-2026';

    private function owner(): User
    {
        return User::factory()->superAdmin()->create(['password' => self::OWNER_PASSWORD]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'همکار آزمایشی', 'email' => 'staff@novinpay.test',
            'password' => self::STAFF_PASSWORD, 'password_confirmation' => self::STAFF_PASSWORD,
            'current_password' => self::OWNER_PASSWORD, 'is_active' => '1',
            'permissions' => ['dashboard.view', 'products.view'],
        ], $overrides);
    }

    private function product(): Product
    {
        return Product::create(['name' => 'محصول آزمایشی', 'slug' => 'protected-product', 'category' => 'pos_terminal', 'condition' => 'new', 'stock_status' => 'in_stock', 'is_published' => false]);
    }

    private function repair(): RepairRequest
    {
        $repair = new RepairRequest;
        $repair->tracking_reference = 'NP-'.strtoupper(bin2hex(random_bytes(16)));
        $repair->submission_digest = bin2hex(random_bytes(32));
        $repair->customer_name = 'PRIVATE-CUSTOMER';
        $repair->contact_phone = '09123456789';
        $repair->phone_digest = RepairIdentity::digest('phone', '09123456789');
        $repair->device_brand = 'PAX';
        $repair->device_model = '<script>PRIVATE-MODEL</script>';
        $repair->issue = 'PRIVATE-ISSUE: <script>unsafe()</script>';
        $repair->status = 'online_submitted';
        $repair->save();

        return $repair;
    }

    private function grant(User $user): AdminAccount
    {
        return AdminAccount::query()->where('user_id', $user->id)->sole();
    }

    public function test_super_admin_has_dashboard_and_management_access_without_email_runtime_dependency(): void
    {
        $owner = $this->owner();
        config(['admin.email' => null]);
        $this->actingAs($owner);
        foreach (['/admin', '/admin/products', '/admin/staff', '/admin/staff/create', '/admin/activities', '/admin/repair-requests', '/admin/account/password'] as $uri) {
            $response = $this->get($uri)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }

    public function test_owner_cannot_be_edited_deactivated_demoted_or_deleted_through_staff_routes(): void
    {
        $owner = $this->owner();
        $account = $this->grant($owner);
        $before = $account->fresh()->getAttributes();
        $credentials = $owner->fresh()->getAttributes();
        $this->actingAs($owner)->get('/admin/staff/'.$account->id.'/edit')->assertForbidden();
        $this->put('/admin/staff/'.$account->id, $this->payload(['email' => $owner->email, 'is_active' => '0']))->assertForbidden();
        $this->delete('/admin/staff/'.$account->id)->assertStatus(405);
        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertSame($credentials, $owner->fresh()->getAttributes());
    }

    public function test_staff_without_permissions_defaults_to_denial_and_cannot_escalate(): void
    {
        $owner = $this->owner();
        $staff = User::factory()->staff()->create();
        $this->actingAs($staff);
        foreach (['/admin', '/admin/products', '/admin/staff', '/admin/staff/create', '/admin/repair-requests', '/admin/activities'] as $uri) {
            $this->get($uri)->assertForbidden();
        }
        $this->post('/admin/staff', $this->payload(['role' => 'super_admin']))->assertForbidden();
        $this->put('/admin/staff/'.$this->grant($owner)->id, $this->payload())->assertForbidden();
        $this->get('/admin/account/password')->assertOk();
        $staff->email = $owner->email;
        $staff->role = 'super_admin';
        $this->assertFalse(Gate::forUser($staff)->allows('staff.manage'));
        $this->assertFalse(Gate::forUser($staff)->allows('products.manage'));
        $this->assertDatabaseCount('admin_accounts', 2);
    }

    public function test_product_view_permission_cannot_write_through_direct_http_requests(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $before = $product->fresh()->getAttributes();
        $staff = User::factory()->staff(['products.view'])->create();
        $this->actingAs($staff)->get('/admin/products')->assertOk()
            ->assertDontSee(route('admin.products.create'))->assertSee('فقط مشاهده');
        $this->get('/admin/products/create')->assertForbidden();
        $this->get('/admin/products/'.$product->slug.'/edit')->assertForbidden();
        $this->post('/admin/products', ['name' => 'forged'])->assertForbidden();
        $this->put('/admin/products/'.$product->slug, ['name' => 'forged'])->assertForbidden();
        $this->patch('/admin/products/'.$product->slug, ['is_published' => true])->assertForbidden();
        $this->delete('/admin/products/'.$product->slug)->assertForbidden();
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_activities', 0);
    }

    public function test_staff_provisioning_requires_owner_reauthentication_and_does_not_flash_passwords(): void
    {
        $this->actingAs($this->owner());
        $this->from('/admin/staff/create')->post('/admin/staff', $this->payload(['current_password' => 'wrong']))
            ->assertSessionHasErrors('current_password');
        $this->assertNull(session('_old_input.current_password'));
        $this->assertNull(session('_old_input.password'));
        $this->assertNull(session('_old_input.password_confirmation'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('admin_activities', 0);
        $this->post('/admin/staff', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->assertDatabaseCount('admin_accounts', 1);
    }

    public function test_forged_unknown_and_dependent_permissions_are_rejected(): void
    {
        $this->actingAs($this->owner());
        foreach ([['role' => 'super_admin'], ['owner_slot' => 1], ['user_id' => 99], ['session_version' => 99], ['must_change_password' => false]] as $forged) {
            $this->post('/admin/staff', $this->payload($forged))->assertSessionHasErrors(array_key_first($forged));
        }
        foreach ([['staff.manage'], ['settings.manage'], ['products.manage'], ['repairs.customer_data'], ['products.view', 'products.view']] as $permissions) {
            $response = $this->post('/admin/staff', $this->payload(['permissions' => $permissions]));
            $response->assertSessionHasErrors();
            $response->assertRedirect();
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_new_staff_is_forced_to_change_password_before_any_business_action(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/admin/staff', $this->payload())->assertRedirect(route('admin.staff.index'));
        $staff = User::query()->where('email', 'staff@novinpay.test')->sole();
        $this->assertTrue($this->grant($staff)->must_change_password);
        $this->assertTrue(Hash::check(self::STAFF_PASSWORD, $staff->password));
        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => $staff->email, 'password' => self::STAFF_PASSWORD])->assertRedirect(route('admin.password.edit'));
        $this->get('/admin')->assertRedirect(route('admin.password.edit'));
        $this->post('/admin/products', ['name' => 'blocked'])->assertRedirect(route('admin.password.edit'));
        $this->put('/admin/account/password', ['current_password' => self::STAFF_PASSWORD, 'password' => 'Staff-Personal-2026', 'password_confirmation' => 'Staff-Personal-2026', 'user_id' => $owner->id])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertFalse($this->grant($staff)->must_change_password);
        $this->assertSame(2, $this->grant($staff)->session_version);
        $this->assertSame(2, session('admin_session_version'));
        $this->assertTrue(Hash::check('Staff-Personal-2026', $staff->fresh()->password));
        $this->assertTrue(Hash::check(self::OWNER_PASSWORD, $owner->fresh()->password));
        $this->get('/admin')->assertOk();
    }

    public function test_permission_changes_invalidate_existing_sessions_and_apply_on_relogin(): void
    {
        $owner = $this->owner();
        $staff = User::factory()->staff(['products.view'])->create(['password' => self::STAFF_PASSWORD]);
        $oldToken = $staff->remember_token;
        $this->actingAs($owner)->put('/admin/staff/'.$this->grant($staff)->id, $this->payload([
            'email' => $staff->email, 'password' => '', 'password_confirmation' => '', 'permissions' => ['activity.view'],
        ]))->assertRedirect(route('admin.staff.index'));
        $this->assertSame(2, $this->grant($staff)->session_version);
        $this->assertNotSame($oldToken, $staff->fresh()->remember_token);
        $this->actingAs($staff)->withSession(['admin_session_version' => 1])->get('/admin/products')->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->post('/admin/login', ['email' => $staff->email, 'password' => self::STAFF_PASSWORD])->assertRedirect(route('admin.activities.index'));
        $this->get('/admin/products')->assertForbidden();
        $this->get('/admin/activities')->assertOk();
        $this->assertDatabaseHas('admin_activities', ['actor_id' => $owner->id, 'action' => AdminAction::PermissionsChanged->value, 'target_id' => $staff->id]);
    }

    public function test_deactivation_denies_login_and_reactivation_does_not_restore_old_sessions(): void
    {
        $owner = $this->owner();
        $staff = User::factory()->staff(['products.view'])->create(['password' => self::STAFF_PASSWORD]);
        $data = $this->payload(['email' => $staff->email, 'password' => '', 'password_confirmation' => '', 'permissions' => ['products.view'], 'is_active' => '0']);
        $this->actingAs($owner)->put('/admin/staff/'.$this->grant($staff)->id, $data)->assertRedirect();
        $this->actingAs($staff)->withSession(['admin_session_version' => 1])->get('/admin/products')->assertRedirect(route('admin.login'));
        $this->post('/admin/login', ['email' => $staff->email, 'password' => self::STAFF_PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($owner)->put('/admin/staff/'.$this->grant($staff)->id, array_replace($data, ['is_active' => '1']))->assertRedirect();
        $this->assertSame(3, $this->grant($staff)->session_version);
        $this->actingAs($staff)->withSession(['admin_session_version' => 1])->get('/admin/products')->assertRedirect(route('admin.login'));
        $this->assertDatabaseHas('admin_activities', ['action' => AdminAction::Deactivated->value, 'target_id' => $staff->id]);
        $this->assertDatabaseHas('admin_activities', ['action' => AdminAction::Activated->value, 'target_id' => $staff->id]);
    }

    public function test_owner_password_reset_requires_staff_to_choose_a_new_password_again(): void
    {
        $owner = $this->owner();
        $staff = User::factory()->staff(['products.view'])->create();
        $this->actingAs($owner)->put('/admin/staff/'.$this->grant($staff)->id, $this->payload(['email' => $staff->email, 'permissions' => ['products.view']]))->assertRedirect();
        $this->assertTrue($this->grant($staff)->must_change_password);
        $this->assertSame(2, $this->grant($staff)->session_version);
        $this->assertDatabaseHas('admin_activities', ['action' => AdminAction::PasswordReset->value, 'target_id' => $staff->id]);
    }

    public function test_repair_metadata_permission_does_not_disclose_customer_fields_or_allow_updates(): void
    {
        $repair = $this->repair();
        $this->actingAs(User::factory()->staff(['repairs.view'])->create());
        foreach (['/admin/repair-requests', '/admin/repair-requests/'.$repair->id] as $uri) {
            $response = $this->get($uri)->assertOk()->assertSee('دریافت فیزیکی');
            foreach ([$repair->customer_name, $repair->contact_phone, 'PRIVATE-MODEL', 'PRIVATE-ISSUE'] as $private) {
                $response->assertDontSee($private);
            }
        }
        $this->put('/admin/repair-requests/'.$repair->id, ['status' => 'received'])->assertStatus(405);
        $this->delete('/admin/repair-requests/'.$repair->id)->assertStatus(405);
        $this->assertSame('online_submitted', $repair->fresh()->status->value);
        $this->assertDatabaseCount('admin_activities', 0);
    }

    public function test_customer_data_permission_is_record_checked_escaped_and_audited_without_payloads(): void
    {
        $repair = $this->repair();
        $staff = User::factory()->staff(['repairs.view', 'repairs.customer_data'])->create();
        $this->actingAs($staff)->get('/admin/repair-requests/'.$repair->id)->assertOk()
            ->assertSee($repair->customer_name)->assertSee($repair->contact_phone)
            ->assertSee(e($repair->device_model), false)->assertDontSee('<script>PRIVATE-MODEL</script>', false);
        $this->get('/admin/repair-requests')->assertDontSee($repair->customer_name)->assertDontSee($repair->contact_phone);
        $this->assertDatabaseHas('admin_activities', ['actor_id' => $staff->id, 'action' => AdminAction::RepairCustomerViewed->value, 'target_type' => 'repair_request', 'target_id' => $repair->id]);
        $logs = DB::table('admin_activities')->get()->toJson();
        foreach ([$repair->customer_name, $repair->contact_phone, $repair->device_model, $repair->issue, $repair->tracking_reference, $staff->password, $staff->remember_token] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $logs);
        }
        $ordinary = User::factory()->staff(['dashboard.view'])->create();
        $this->actingAs($ordinary)->get('/admin/repair-requests/'.$repair->id)->assertForbidden();
        $this->assertFalse(Gate::forUser($ordinary)->allows('viewCustomerData', $repair));
        $this->assertFalse(Gate::forUser($staff)->allows('viewCustomerData', new RepairRequest));
    }

    public function test_dashboard_counts_are_real_and_scoped_to_permissions(): void
    {
        $this->product();
        $this->repair();
        User::factory()->staff()->create();
        User::factory()->staff([], false)->create();
        $this->actingAs($this->owner())->get('/admin')->assertOk()
            ->assertViewHas('productCount', 1)->assertViewHas('publishedCount', 0)
            ->assertViewHas('repairCount', 1)->assertViewHas('staffCount', 1)->assertDontSee('PRIVATE-CUSTOMER');
        $this->actingAs(User::factory()->staff(['dashboard.view'])->create())->get('/admin')->assertOk()
            ->assertViewHas('productCount', null)->assertViewHas('repairCount', null)->assertViewHas('staffCount', null)
            ->assertDontSee('PRIVATE-CUSTOMER')->assertSee('دسترسی به درخواست‌ها ندارید');
    }

    public function test_history_is_append_only_and_has_no_write_routes(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/admin/staff', $this->payload())->assertRedirect();
        $activity = AdminActivity::query()->firstOrFail();
        $before = $activity->getAttributes();
        try {
            $activity->action = AdminAction::Logout;
            $activity->save();
            $this->fail('History was modified');
        } catch (LogicException) {
            $this->assertSame($before, $activity->fresh()->getAttributes());
        }
        try {
            $activity->delete();
            $this->fail('History was deleted');
        } catch (LogicException) {
            $this->assertDatabaseHas('admin_activities', ['id' => $activity->id]);
        }
        $staff = User::factory()->staff(['activity.view'])->create();
        $this->actingAs($staff)->get('/admin/activities')->assertOk();
        foreach (['post', 'put', 'delete'] as $method) {
            $this->$method('/admin/activities', [])->assertStatus(405);
        }
        $logs = DB::table('admin_activities')->get()->toJson();
        foreach ([self::OWNER_PASSWORD, self::STAFF_PASSWORD, $owner->password, $owner->remember_token, 'staff@novinpay.test'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $logs);
        }
    }

    public function test_guest_direct_requests_are_blocked_and_csrf_is_enforced_for_admin_writes(): void
    {
        foreach (['/admin/staff', '/admin/staff/create', '/admin/activities', '/admin/repair-requests', '/admin/account/password'] as $uri) {
            $this->get($uri)->assertRedirect(route('admin.login'));
        }
        $this->post('/admin/staff', $this->payload())->assertRedirect(route('admin.login'));
        $this->put('/admin/account/password', [])->assertRedirect(route('admin.login'));
        $this->actingAs($this->owner());
        $this->app->detectEnvironment(fn () => 'local');
        $this->post('/admin/staff', $this->payload())->assertStatus(419);
        $this->put('/admin/account/password', [])->assertStatus(419);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_product_editing_staff_can_manage_products_and_actions_are_attributed(): void
    {
        $staff = User::factory()->staff(['products.view', 'products.manage'])->create();
        $this->actingAs($staff)->post('/admin/products', ['name' => 'Test staff product', 'slug' => 'staff-product', 'category' => 'pos_terminal', 'condition' => 'new', 'stock_status' => 'in_stock'])->assertRedirect(route('admin.products.index'));
        $product = Product::query()->sole();
        $this->put('/admin/products/'.$product->slug, ['name' => 'Updated staff product', 'slug' => 'staff-product', 'category' => 'pos_terminal', 'condition' => 'new', 'stock_status' => 'in_stock'])->assertRedirect();
        $this->delete('/admin/products/'.$product->slug)->assertRedirect();
        $this->assertDatabaseCount('products', 0);
        foreach ([AdminAction::ProductCreated, AdminAction::ProductUpdated, AdminAction::ProductDeleted] as $action) {
            $this->assertDatabaseHas('admin_activities', ['actor_id' => $staff->id, 'action' => $action->value, 'target_type' => 'product', 'target_id' => $product->id]);
        }
        $this->get('/admin/staff')->assertForbidden();
    }

    public function test_legacy_sessions_are_rejected_and_staff_login_chooses_an_authorized_landing_page(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->withSession(['admin_session_version' => null])->get('/admin')->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $staff = User::factory()->staff(['products.view'])->create(['password' => self::STAFF_PASSWORD]);
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->post('/admin/login', ['email' => $staff->email, 'password' => self::STAFF_PASSWORD])->assertRedirect(route('admin.products.index'));
        $this->get('/admin/products')->assertOk();
        $this->get('/admin')->assertForbidden();
    }

    public function test_staff_form_handles_malformed_nested_input_and_never_echoes_passwords(): void
    {
        $this->actingAs($this->owner())->from('/admin/staff/create')->post('/admin/staff', $this->payload([
            'name' => ['<script>bad()</script>'], 'email' => ['invalid'], 'permissions' => 'products.view',
        ]))->assertSessionHasErrors(['name', 'email', 'permissions']);
        $this->get('/admin/staff/create')->assertOk()->assertDontSee('<script>bad()</script>', false)
            ->assertDontSee('value="'.self::OWNER_PASSWORD.'"', false)->assertDontSee('value="'.self::STAFF_PASSWORD.'"', false);
    }

    public function test_staff_creation_rolls_back_if_activity_recording_fails(): void
    {
        $this->actingAs($this->owner());
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'admin_activities')) {
                throw new \RuntimeException('Synthetic audit storage failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/staff', $this->payload());
            $this->fail('An unaudited staff account was created');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Synthetic audit storage failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('admin_accounts', 1);
        $this->assertDatabaseCount('admin_activities', 0);
    }
}
