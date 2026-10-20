<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.email' => 'admin@novinpay.test']);
    }

    public function test_ordinary_user_cannot_log_in_through_admin_form_even_with_valid_credentials(): void
    {
        User::factory()->create(['email' => 'customer@novinpay.test', 'password' => 'secret-password']);

        $this->post(route('admin.login.store'), [
            'email' => 'customer@novinpay.test',
            'password' => 'secret-password',
            'is_admin' => true,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_matching_email_without_database_grant_rejects_valid_credentials(): void
    {
        User::factory()->create(['email' => 'admin@novinpay.test', 'password' => 'secret-password']);
        config(['admin.email' => null]);

        $this->post(route('admin.login.store'), [
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public static function registrationProvider(): array
    {
        return [
            'register' => ['POST', '/register'],
            'admin register' => ['POST', '/admin/register'],
            'admin register page' => ['GET', '/admin/register'],
            'admin signup' => ['POST', '/admin/signup'],
        ];
    }

    public function test_login_page_renders_persian_rtl(): void
    {
        $response = $this->get(route('admin.login'));

        $response->assertOk();
        $response->assertSee('lang="fa"', false);
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('ورود به پنل مدیریت');
        $response->assertSee('ثبت‌نام عمومی در این وب‌سایت وجود ندارد.');
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->superAdmin()->create([
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ]);

        $response = $this->from(route('admin.login'))->post(route('admin.login.store'), [
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_redirects_back_to_previously_requested_admin_page(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ]);

        $this->get(route('admin.products.index'))->assertRedirect(route('admin.login'));

        $this->post(route('admin.login.store'), [
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ])->assertRedirect(route('admin.products.index'));

        $this->get(route('admin.products.index'))->assertOk();
    }

    public function test_invalid_credentials_are_rejected_with_persian_message(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ]);

        $response = $this->from(route('admin.login'))->post(route('admin.login.store'), [
            'email' => 'admin@novinpay.test',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('admin.login'));
        $response->assertSessionHasErrors('email');
        $this->assertSame('ایمیل یا گذرواژه معتبر نیست.', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_unknown_account_is_rejected(): void
    {
        $this->from(route('admin.login'))->post(route('admin.login.store'), [
            'email' => 'nobody@novinpay.test',
            'password' => 'secret-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_missing_credentials_receive_persian_messages(): void
    {
        $this->from(route('admin.login'))
            ->post(route('admin.login.store'), [])
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertStringContainsString('الزامی است', session('errors')->first('email'));
        $this->assertSame('گذرواژه الزامی است.', session('errors')->first('password'));
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('admin.login.store'), [
                'email' => 'admin@novinpay.test',
                'password' => 'wrong-password-'.$attempt,
            ])->assertSessionHasErrors('email');
        }

        $response = $this->from(route('admin.login'))->post(route('admin.login.store'), [
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->get(route('admin.login'))->assertOk()->assertSee('ثانیه دیگر');
    }

    public function test_ip_throttle_blocks_attempts_across_multiple_emails(): void
    {
        config(['admin.login_ip_max_attempts' => 3]);

        foreach (range(1, 3) as $attempt) {
            $this->post(route('admin.login.store'), [
                'email' => "wrong-user-{$attempt}@novinpay.test",
                'password' => 'wrong-password-'.$attempt,
            ])->assertSessionHasErrors('email');
        }

        User::factory()->superAdmin()->create([
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ]);

        $response = $this->from(route('admin.login'))->post(route('admin.login.store'), [
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->get(route('admin.login'))->assertOk()->assertSee('ثانیه دیگر');
    }

    public function test_malformed_email_attempts_count_toward_ip_throttle(): void
    {
        config(['admin.login_ip_max_attempts' => 2]);

        foreach (range(1, 2) as $attempt) {
            $this->from(route('admin.login'))
                ->post(route('admin.login.store'), [
                    'email' => 'not-an-email-'.$attempt,
                    'password' => 'whatever',
                ])
                ->assertSessionHasErrors('email');
        }

        $response = $this->from(route('admin.login'))->post(route('admin.login.store'), [
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->get(route('admin.login'))->assertOk()->assertSee('ثانیه دیگر');
    }

    public function test_successful_login_resets_throttle_counters(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ]);

        foreach (range(1, 4) as $attempt) {
            $this->post(route('admin.login.store'), [
                'email' => 'admin@novinpay.test',
                'password' => 'wrong-password-'.$attempt,
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('admin.login.store'), [
            'email' => 'admin@novinpay.test',
            'password' => 'secret-password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));

        $this->post(route('admin.login.store'), [
            'email' => 'admin@novinpay.test',
            'password' => 'wrong-password-after-success',
        ])->assertSessionHasErrors('email');

        $this->assertSame(
            'ایمیل یا گذرواژه معتبر نیست.',
            session('errors')->first('email')
        );
        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
        $this->get(route('admin.products.index'))->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_user_is_redirected_away_from_login_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.login'))
            ->assertRedirect('/admin');
    }

    #[DataProvider('registrationProvider')]
    public function test_registration_endpoints_do_not_exist(string $method, string $uri): void
    {
        $this->call($method, $uri)->assertNotFound();
    }

    public function test_admin_seeder_rejects_missing_credentials(): void
    {
        $seeder = new AdminUserSeeder;

        $this->expectException(RuntimeException::class);

        $seeder->provision(null, null);
    }

    public function test_admin_seeder_rejects_short_password(): void
    {
        $seeder = new AdminUserSeeder;

        $this->expectException(RuntimeException::class);

        $seeder->provision('admin@novinpay.test', 'short');
    }

    public function test_admin_seeder_creates_the_single_admin_account(): void
    {
        $seeder = new AdminUserSeeder;

        $seeder->provision('admin@novinpay.test', 'long-enough-password');

        $this->assertDatabaseHas('users', ['email' => 'admin@novinpay.test', 'name' => 'مدیر']);
        $this->assertSame(1, User::query()->count());
    }

    public function test_admin_seeder_run_reads_credentials_from_config(): void
    {
        // AdminUserSeeder::run() must read config('admin.*') (not env()) so
        // provisioning keeps working after `php artisan config:cache`.
        config([
            'admin.email' => 'cached-admin@novinpay.test',
            'admin.password' => 'long-enough-password',
        ]);

        (new AdminUserSeeder)->run();

        $this->assertDatabaseHas('users', ['email' => 'cached-admin@novinpay.test']);
        $this->assertSame(1, User::query()->count());
    }

    public function test_login_ip_throttle_limit_is_configurable(): void
    {
        $this->assertSame(30, (int) config('admin.login_ip_max_attempts'));
    }

    public function test_default_database_seeder_creates_no_login_accounts(): void
    {
        $this->seed();

        $this->assertSame(0, User::query()->count());
    }
}
