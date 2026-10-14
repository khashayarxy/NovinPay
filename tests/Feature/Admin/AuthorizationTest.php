<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

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
