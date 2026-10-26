<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminAction;
use App\Http\Middleware\PublicSiteMaintenance;
use App\Models\AdminAccount;
use App\Models\AdminActivity;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Support\SiteMaintenanceState;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\SiteMaintenanceStateTest;

class SiteMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private SiteMaintenanceState $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/novinpay-admin-maintenance-'.bin2hex(random_bytes(8));
        $this->state = new SiteMaintenanceState($this->directory);
        $this->app->instance(SiteMaintenanceState::class, $this->state);
    }

    protected function tearDown(): void
    {
        try {
            $this->assertStringStartsWith(sys_get_temp_dir().'/novinpay-admin-maintenance-', $this->directory);
            (new Filesystem)->deleteDirectory($this->directory);
        } finally {
            parent::tearDown();
        }
    }

    private function input(array $extra = []): array
    {
        return $extra + ['version' => $this->state->read()['version'], 'confirm' => '1', 'current_password' => 'password'];
    }

    private function enable(): void
    {
        $this->actingAs(auth()->user() ?? User::factory()->superAdmin()->create());
        $this->post('/admin/maintenance/enable', $this->input())->assertRedirect('/admin/maintenance');
        $this->assertTrue($this->state->read()['enabled']);
    }

    public function test_every_malformed_timestamp_keeps_public_responses_closed_and_admin_status_safe(): void
    {
        $this->enable();
        $original = $this->state->read();
        foreach (SiteMaintenanceStateTest::malformedTimestamps() as [$timestamp]) {
            $corrupt = $original;
            $corrupt['updated_at'] = $timestamp;
            $bytes = json_encode($corrupt, JSON_THROW_ON_ERROR);
            file_put_contents($this->directory.'/state.json', $bytes);
            $this->get('/products')->assertStatus(503)->assertSee('در حال تعمیرات و به‌روزرسانی سایت نوین پی هستیم')
                ->assertHeader('Retry-After', '60')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $json = $this->getJson('/products')->assertStatus(503)->assertExactJson(['message' => 'Service Unavailable']);
            $this->assertStringContainsString('no-store', $json->headers->get('Cache-Control'));
            $this->head('/products')->assertStatus(503)->assertContent('')->assertHeader('Retry-After', '60');
            $this->get('/admin/maintenance')->assertOk()->assertSee('خطای خواندن وضعیت')->assertDontSee('name="current_password"', false);
            $this->assertSame($bytes, file_get_contents($this->directory.'/state.json'));
        }
    }

    public function test_middleware_does_not_normalize_malformed_raw_targets_into_admin_exemptions(): void
    {
        $this->enable();
        foreach (SiteMaintenanceStateTest::malformedTargets() as [$target]) {
            $request = Request::create('/admin/login');
            $request->server->set('REQUEST_URI', $target);
            $response = app(PublicSiteMaintenance::class)->handle($request, function () {
                $this->fail('Malformed target entered the admin corridor');
            });
            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame('60', $response->headers->get('Retry-After'));
        }
    }

    public function test_anonymous_and_ordinary_users_cannot_control_or_preview(): void
    {
        foreach (['/admin/maintenance', '/admin/maintenance/preview', '/admin/maintenance/preview/page'] as $path) {
            $this->get($path)->assertRedirect('/admin/login');
        }
        $this->post('/admin/maintenance/enable', [])->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        foreach (['/admin/maintenance', '/admin/maintenance/preview/page'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function test_staff_default_denial_and_separate_read_and_write_permissions(): void
    {
        $staff = User::factory()->staff([])->create();
        $this->actingAs($staff)->get('/admin/maintenance')->assertForbidden();
        // User relationships are not trusted; persisted grants are queried again.
        $account = AdminAccount::where('user_id', $staff->id)->firstOrFail();
        $account->permissions = ['maintenance.view'];
        $account->save();
        $this->get('/admin/maintenance')->assertOk()->assertSee('فقط اجازهٔ مشاهده');
        $this->get('/admin/maintenance/preview')->assertOk();
        $this->post('/admin/maintenance/enable', $this->input())->assertForbidden();
        $account->permissions = ['maintenance.manage'];
        $account->save();
        $this->post('/admin/maintenance/enable', $this->input())->assertForbidden();
        $account->permissions = ['maintenance.view', 'maintenance.manage'];
        $account->save();
        $this->post('/admin/maintenance/enable', $this->input())->assertRedirect();
        $this->assertTrue($this->state->read()['enabled']);
        $account->is_active = false;
        $account->save();
        $this->get('/admin/maintenance/preview/page')->assertRedirect('/admin/login');
    }

    public function test_confirmation_password_and_stale_versions_are_enforced(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $old = $this->input();
        $this->postJson('/admin/maintenance/enable', $this->input(['confirm' => '0']))->assertUnprocessable();
        $this->postJson('/admin/maintenance/enable', $this->input(['current_password' => 'wrong']))->assertUnprocessable();
        $this->assertDirectoryDoesNotExist($this->directory);
        $this->post('/admin/maintenance/enable', $old)->assertRedirect();
        $this->postJson('/admin/maintenance/disable', $old)->assertConflict();
        $this->assertTrue($this->state->read()['enabled']);
    }

    public function test_real_csrf_protection_is_not_bypassed_by_the_admin_corridor(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        // Laravel skips CSRF in testing; explicitly enable the real middleware.
        $this->app['env'] = 'csrf-verification';
        $this->post('/admin/maintenance/enable', $this->input())->assertStatus(419);
        $this->assertFalse($this->state->read()['enabled']);
        $this->withSession(['_token' => 'maintenance-csrf-token'])
            ->post('/admin/maintenance/enable', $this->input(['_token' => 'maintenance-csrf-token']))->assertRedirect();
        $this->assertTrue($this->state->read()['enabled']);
    }

    public function test_public_html_json_posts_and_heads_are_blocked_without_new_business_rows(): void
    {
        $this->enable();
        $counts = [];
        foreach (['sales_orders', 'financial_entries', 'repair_requests', 'consultations'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $before = file_get_contents($this->directory.'/state.json');
        $this->get('/products')->assertStatus(503)->assertHeader('Retry-After', '60')
            ->assertSee('در حال تعمیرات و به‌روزرسانی سایت نوین پی هستیم')->assertDontSee('np-header', false);
        $this->getJson('/products')->assertStatus(503)->assertExactJson(['message' => 'Service Unavailable']);
        $this->post('/repair', ['contact_phone' => '09000000000'])->assertStatus(503);
        $this->postJson('/consultation', [])->assertStatus(503);
        $this->head('/')->assertStatus(503)->assertContent('');
        $this->get('/up')->assertStatus(503);
        foreach (['/administrator', '/admin/../products', '/admin%2f..%2fproducts', '/admin//products', '/ADMIN/login'] as $path) {
            $this->get($path)->assertStatus(503);
        }
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
        $this->assertSame($before, file_get_contents($this->directory.'/state.json'));
    }

    public function test_admin_can_sign_in_during_maintenance_and_disable_without_a_public_bypass(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $this->state->change(SiteMaintenanceState::INITIAL, true, $owner->id, SiteMaintenanceState::render(base_path()));
        $this->get('/admin/login')->assertOk();
        $this->post('/admin/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect();
        $this->get('/admin/maintenance')->assertOk()->assertSee('وضعیت فعلی: فعال');
        $this->get('/products?maintenance_bypass=1')->assertStatus(503);
        $this->post('/admin/maintenance/disable', $this->input())->assertRedirect();
        $this->assertFalse($this->state->read()['enabled']);
        $this->get('/')->assertOk();
        $this->get('/products')->assertOk();
    }

    public function test_the_admin_corridor_grants_no_anonymous_or_ordinary_account_access_while_enabled(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $this->state->change(SiteMaintenanceState::INITIAL, true, $owner->id, SiteMaintenanceState::render(base_path()));
        foreach (['/admin/maintenance', '/admin/maintenance/preview/page', '/admin/finance', '/admin/repair-requests'] as $path) {
            $this->get($path)->assertRedirect('/admin/login');
        }
        $this->postJson('/admin/finance/expense', [])->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->get('/admin/finance')->assertForbidden();
        $this->get('/admin/maintenance/preview/page')->assertForbidden();
        $this->post('/admin/maintenance/disable', $this->input())->assertForbidden();
        $this->assertTrue($this->state->read()['enabled']);
    }

    public function test_preview_is_identical_private_and_does_not_write_global_state_or_activity(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $activity = AdminActivity::count();
        $this->get('/admin/maintenance/preview')->assertOk()->assertSee('فقط پیش‌نمایش');
        $response = $this->get('/admin/maintenance/preview/page')->assertOk()
            ->assertHeader('X-NovinPay-Maintenance-Preview', 'true')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertSame(SiteMaintenanceState::render(base_path()), $response->getContent());
        $this->assertDirectoryDoesNotExist($this->directory);
        $this->assertSame($activity, AdminActivity::count());
        $this->enable();
        $before = file_get_contents($this->directory.'/state.json');
        $this->get('/admin/maintenance/preview/page')->assertOk();
        $this->assertSame($before, file_get_contents($this->directory.'/state.json'));
    }

    public function test_actions_are_audited_without_passwords_html_or_sensitive_payloads(): void
    {
        $this->enable();
        $this->post('/admin/maintenance/disable', $this->input())->assertRedirect();
        $this->assertDatabaseHas('admin_activities', ['action' => AdminAction::MaintenanceEnabled->value]);
        $this->assertDatabaseHas('admin_activities', ['action' => AdminAction::MaintenanceDisabled->value]);
        $journal = file_get_contents($this->directory.'/audit.jsonl');
        $this->assertSame(4, substr_count(trim($journal), "\n") + 1);
        foreach (['password', 'data:image', 'APP_KEY', 'contact_phone', 'html'] as $secret) {
            $this->assertStringNotContainsString($secret, $journal);
        }
    }

    public function test_corrupt_state_fails_closed_but_admin_recovery_and_preview_remain_available(): void
    {
        $this->enable();
        file_put_contents($this->directory.'/state.json', '{interrupted');
        $this->get('/products')->assertStatus(503);
        $this->get('/admin/maintenance')->assertOk()->assertSee('خطای خواندن وضعیت');
        $this->get('/admin/maintenance/preview/page')->assertOk();
        file_put_contents($this->directory.'/state.json', json_encode(['format' => 1, 'enabled' => true, 'version' => str_repeat('a', 64), 'updated_at' => 'synthetic', 'html' => 'invalid snapshot', 'html_sha256' => 123]));
        $this->get('/products')->assertStatus(503)->assertSee('در حال تعمیرات و به‌روزرسانی سایت نوین پی هستیم');
    }

    public function test_permission_revocation_is_effective_in_the_existing_session(): void
    {
        $staff = User::factory()->staff(['maintenance.view', 'maintenance.manage'])->create();
        $this->actingAs($staff);
        $this->post('/admin/maintenance/enable', $this->input())->assertRedirect();
        $account = AdminAccount::where('user_id', $staff->id)->firstOrFail();
        $account->permissions = [];
        $account->save();
        $this->post('/admin/maintenance/disable', $this->input())->assertForbidden();
        $this->get('/admin/maintenance/preview/page')->assertForbidden();
        $this->assertTrue($this->state->read()['enabled']);
    }

    public function test_database_audit_failure_reports_applied_state_without_losing_the_durable_audit(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        AdminActivity::creating(function (): void {
            throw new \RuntimeException('synthetic unavailable audit database');
        });
        $response = $this->post('/admin/maintenance/enable', $this->input());
        $response->assertRedirect('/admin/maintenance')->assertSessionHas('maintenance_warning')->assertSessionMissing('success');
        $this->assertTrue($this->state->read()['enabled']);
        $this->assertStringContainsString('"phase":"applied"', file_get_contents($this->directory.'/audit.jsonl'));
        $this->get('/admin/maintenance')->assertOk()->assertSee('وضعیت فعلی: فعال');
    }

    public function test_no_payment_callback_is_invented_or_exempted_and_manual_finance_remains_idempotent(): void
    {
        $this->enable();
        $external = array_filter(app('router')->getRoutes()->getRoutes(), fn ($route) => preg_match('~(?:^|/)(?:payment|callback|webhook|api)(?:/|$)~i', $route->uri()));
        $this->assertSame([], array_values($external), 'New external payment/API routes need an explicit maintenance safety review.');
        foreach (['/payment/callback', '/api/payment/verify', '/webhook/payment'] as $path) {
            $this->postJson($path, [])->assertStatus(503);
        }
        $payload = ['operation_key' => (string) Str::uuid(), 'password' => 'password', 'reason' => 'Synthetic maintenance regression', 'amount' => '11.23', 'category' => 'operating', 'reference' => 'EXP-MAINTENANCE-001', 'method' => 'cash'];
        $this->post('/admin/finance/expense', $payload)->assertRedirect();
        $this->post('/admin/finance/expense', $payload)->assertRedirect();
        $this->assertSame(1, FinancialEntry::count());
        $this->assertSame(1123, (int) FinancialEntry::sum('expense_delta'));
    }
}
