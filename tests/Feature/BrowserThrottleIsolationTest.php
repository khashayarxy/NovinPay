<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BrowserThrottleIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function probe(string $name, string $path): string
    {
        $middleware = array_values(array_filter(Route::getRoutes()->getByName($name)->gatherMiddleware(), fn ($value) => str_starts_with($value, 'throttle:')));
        $this->assertCount(1, $middleware);
        Route::get($path, fn () => response('synthetic'))->middleware(['web', $middleware[0]]);

        return $path;
    }

    public function test_background_chat_does_not_consume_the_first_checkout_attempt_and_checkout_limit_remains(): void
    {
        $chat = $this->probe('support.chat', '/_test/chat-traffic');
        $checkout = $this->probe('cart.checkout', '/_test/checkout-limit');
        for ($i = 0; $i < 11; $i++) {
            $this->get($chat)->assertOk();
        }
        for ($i = 0; $i < 10; $i++) {
            $this->get($checkout)->assertOk();
        }
        $this->get($checkout)->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_cart_updates_share_their_existing_limit_but_do_not_consume_checkout_or_chat_budget(): void
    {
        $add = $this->probe('cart.add', '/_test/cart-add-limit');
        $remove = $this->probe('cart.remove', '/_test/cart-remove-limit');
        $checkout = $this->probe('cart.checkout', '/_test/independent-checkout');
        $chat = $this->probe('support.chat', '/_test/independent-chat');
        for ($i = 0; $i < 30; $i++) {
            $this->get($i % 2 === 0 ? $add : $remove)->assertOk();
        }
        $this->get($add)->assertTooManyRequests();
        $this->get($remove)->assertTooManyRequests();
        $this->get($checkout)->assertOk();
        $this->get($chat)->assertOk();
    }
}
