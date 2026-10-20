<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAccount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.email' => 'admin@novinpay.test']);
    }

    public function test_ordinary_authenticated_user_cannot_read_or_write_any_admin_product_route(): void
    {
        Storage::fake('public');
        $product = Product::create([
            'name' => 'Original', 'slug' => 'protected-product', 'category' => 'pos_terminal',
            'condition' => 'new', 'stock_status' => 'in_stock', 'is_published' => false,
        ]);
        $original = $product->fresh()->getAttributes();
        $this->actingAs(User::factory()->create(['email' => 'customer@novinpay.test']));

        foreach (['/admin', '/admin/products', '/admin/products/create', '/admin/products/protected-product/edit'] as $uri) {
            $this->get($uri)->assertForbidden();
        }

        $payload = [
            'name' => 'Unauthorized change', 'slug' => 'unauthorized-change', 'category' => 'pos_terminal',
            'condition' => 'new', 'stock_status' => 'in_stock', 'is_published' => true,
            'email' => 'admin@novinpay.test', 'is_admin' => true,
            'image' => UploadedFile::fake()->image('unauthorized.jpg'),
        ];
        $this->post('/admin/products', $payload)->assertForbidden();
        $this->put('/admin/products/protected-product', $payload)->assertForbidden();
        $this->patch('/admin/products/protected-product', $payload)->assertForbidden();
        $this->delete('/admin/products/protected-product')->assertForbidden();

        $this->assertSame(1, Product::count());
        $this->assertSame($original, $product->fresh()->getAttributes());
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public static function invalidAdminConfigurationProvider(): array
    {
        return ['missing' => [null], 'empty' => [''], 'whitespace' => ['  '], 'wrong type' => [[]], 'unknown account' => ['unknown@novinpay.test']];
    }

    #[DataProvider('invalidAdminConfigurationProvider')]
    public function test_admin_access_defaults_to_denial(mixed $email): void
    {
        $user = User::factory()->create(['email' => 'admin@novinpay.test']);
        config(['admin.email' => $email]);

        $this->actingAs($user)->get('/admin/products')->assertForbidden();
    }

    public function test_existing_configured_admin_is_authorized_without_reprovisioning(): void
    {
        $admin = User::factory()->create(['email' => 'admin@novinpay.test']);
        $original = $admin->fresh()->getAttributes();
        $this->artisan('admin:bootstrap', ['user' => $admin->id, '--apply' => true])->assertSuccessful();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->get('/admin/products')->assertOk();
        $this->assertSame($original, $admin->fresh()->getAttributes());
    }

    public function test_gate_checks_persisted_identity_and_does_not_trust_a_spoofed_email(): void
    {
        User::factory()->superAdmin()->create(['email' => 'admin@novinpay.test']);
        $ordinary = User::factory()->create(['email' => 'customer@novinpay.test']);
        $ordinary->email = 'admin@novinpay.test';

        $this->assertFalse(Gate::forUser($ordinary)->allows('access-admin'));
        $this->assertFalse(Gate::forUser(new User(['email' => 'admin@novinpay.test']))->allows('access-admin'));
    }

    public function test_removing_authorization_denies_an_existing_session_but_allows_logout(): void
    {
        $admin = User::factory()->staff(['products.view'])->create(['email' => 'staff@novinpay.test']);
        $this->actingAs($admin)->get('/admin/products')->assertOk();
        $adminAccount = AdminAccount::query()->where('user_id', $admin->id)->firstOrFail();
        $adminAccount->is_active = false;
        $adminAccount->save();

        $this->get('/admin/products')->assertRedirect(route('admin.login'));
        $this->actingAs($admin);
        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_every_admin_route_requires_authentication(): void
    {
        $product = Product::create([
            'name' => 'محصول آزمایشی',
            'slug' => 'auth-test-product',
            'category' => 'pos_terminal',
            'condition' => 'new',
            'stock_status' => 'in_stock',
            'is_published' => true,
        ]);

        $requests = [
            'dashboard' => fn () => $this->get('/admin'),
            'index' => fn () => $this->get('/admin/products'),
            'create' => fn () => $this->get('/admin/products/create'),
            'edit' => fn () => $this->get('/admin/products/'.$product->slug.'/edit'),
            'store' => fn () => $this->post('/admin/products', ['name' => 'x']),
            'update' => fn () => $this->put('/admin/products/'.$product->slug, ['name' => 'x']),
            'destroy' => fn () => $this->delete('/admin/products/'.$product->slug),
            'logout' => fn () => $this->post('/admin/logout'),
        ];

        foreach ($requests as $name => $request) {
            $response = $request();

            $this->assertTrue(
                $response->isRedirect(),
                "Route [{$name}] must require authentication, got status {$response->getStatusCode()}."
            );
            $response->assertRedirect(route('admin.login'));
        }

        $this->assertGuest();
        $this->assertDatabaseHas('products', ['slug' => 'auth-test-product']);
    }

    public function test_guest_is_redirected_from_product_edit_with_unknown_slug(): void
    {
        $this->get('/admin/products/does-not-exist/edit')
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_product_paths_are_not_served_to_guests(): void
    {
        $this->get('/admin/products/does-not-exist')
            ->assertStatus(405);
    }

    public function test_login_route_is_public(): void
    {
        $this->get(route('admin.login'))->assertOk();
        $this->post(route('admin.login.store'), [])->assertSessionHasErrors(['email', 'password']);
    }
}
