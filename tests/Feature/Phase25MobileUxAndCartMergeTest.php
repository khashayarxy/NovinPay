<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CommerceManagement;
use App\Services\CustomerLogin;
use App\Services\ShoppingCart;
use App\Support\CustomerLoginFlow;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase25MobileUxAndCartMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        URL::forceRootUrl('https://novinpaypos.test');
        URL::forceScheme('https');
    }

    private function createStockProduct(string $slug, int $stockQty): Product
    {
        $product = Product::create([
            'name' => 'کارتخوان '.$slug,
            'slug' => $slug,
            'category' => 'pos_terminal',
            'is_published' => true,
            'condition' => 'new',
            'stock_status' => 'in_stock',
            'online_purchasing_enabled' => true,
            'price' => 5000000,
        ]);

        $owner = User::query()->first() ?? User::factory()->superAdmin()->create();
        $commerce = app(CommerceManagement::class);
        $itemId = $commerce->execute($owner, 'item.create', [
            'operation_key' => (string) Str::uuid(),
            'sku' => 'SKU-'.strtoupper($slug),
            'name' => 'Stock '.$slug,
            'condition' => 'new',
            'serialized' => false,
            'product_id' => $product->id,
            'low_stock_threshold' => 0,
            'reason' => 'Phase 2.5 cart merge test',
        ]);
        $item = InventoryItem::findOrFail($itemId);
        $commerce->execute($owner, 'item.receive', [
            'operation_key' => (string) Str::uuid(),
            'version' => $item->version,
            'quantity' => $stockQty,
            'reason' => 'Phase 2.5 cart merge test',
        ], $item);

        return $product;
    }

    private function createCustomer(string $phone, string $password = 'StrongPass!123'): CustomerAccount
    {
        $account = new CustomerAccount;
        $account->forceFill([
            'public_id' => (string) Str::uuid(),
            'phone' => $phone,
            'phone_digest' => RepairIdentity::digest('customer-account-phone', $phone),
            'password' => Hash::make($password),
            'verified_at' => now(),
            'is_active' => true,
            'session_version' => 1,
        ])->save();

        return $account->refresh();
    }

    private function cartFields($response): array
    {
        return [
            'cart_context' => $response->viewData('proof'),
            'cart_revision' => $response->viewData('revision'),
        ];
    }

    public function test_guest_cart_merges_into_authenticated_cart_within_stock_limits_and_logout_isolates_device(): void
    {
        $me31 = $this->createStockProduct('me31-merge', 5);
        $s910 = $this->createStockProduct('s910-merge', 4);
        $account = $this->createCustomer('09121112233');

        // 1) Account already owns 2 x ME31 from an earlier device/session.
        $priorDevice = str_repeat('a', 64);
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, $priorDevice)
            ->actingAs($account, 'customer')
            ->withSession(['customer_session_version' => $account->session_version]);

        $priorCart = $this->get(route('cart.index'))->assertOk();
        $this->post(route('cart.add', $me31), $this->cartFields($priorCart) + ['quantity' => 2])
            ->assertRedirect(route('cart.index'));

        $this->post(route('account.logout'))->assertRedirect(route('account.login'));

        // 2) On a fresh guest browser (device D1), guest adds 4 x ME31 (stock=5) and 2 x S910.
        $guestDevice = str_repeat('d', 64);
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, $guestDevice);
        $guestCart1 = $this->get(route('cart.index'))->assertOk();
        $this->post(route('cart.add', $me31), $this->cartFields($guestCart1) + ['quantity' => 4])
            ->assertRedirect(route('cart.index'));

        $guestCart2 = $this->get(route('cart.index'))->assertOk();
        $this->post(route('cart.add', $s910), $this->cartFields($guestCart2) + ['quantity' => 2])
            ->assertRedirect(route('cart.index'));

        $this->assertSame(['me31-merge' => 4, 's910-merge' => 2], $this->get(route('cart.index'))->viewData('quantity'));

        // 3) Guest logs into the account via password login -> quantities sum & cap safely at stock (2 + 4 -> 5, 0 + 2 -> 2).
        $this->post(route('account.password.login'), [
            'identifier' => '09121112233',
            'password' => 'StrongPass!123',
        ])->assertRedirect();

        $mergedCart = $this->get(route('cart.index'))->assertOk();
        $this->assertSame([
            'me31-merge' => 5,
            's910-merge' => 2,
        ], $mergedCart->viewData('quantity'));
        $this->assertSame(5, $mergedCart->viewData('items')['me31-merge']['account']['quantity']);
        $this->assertSame(2, $mergedCart->viewData('items')['s910-merge']['account']['quantity']);
        $this->assertNull($mergedCart->viewData('items')['me31-merge']['device']);

        // 4) User logs out -> device cookie rotates AND guest cart is empty & isolated from the logged-out account.
        $logoutResponse = $this->post(route('account.logout'))->assertRedirect(route('account.login'));
        $rotatedCookie = $logoutResponse->getCookie(ShoppingCart::DEVICE_COOKIE, false);
        $this->assertNotNull($rotatedCookie);
        $this->assertNotSame($guestDevice, $rotatedCookie->getValue());

        // Even before adopting rotated cookie, old guest device bucket was cleanly absorbed; with rotated cookie it is also empty.
        $this->assertSame([], $this->get(route('cart.index'))->assertOk()->viewData('quantity'));
        $this->withCookie(ShoppingCart::DEVICE_COOKIE, $rotatedCookie->getValue());
        $this->assertSame([], $this->get(route('cart.index'))->assertOk()->viewData('quantity'));

        // 5) Account cart remains intact when logging back in via OTP.
        $loginMock = $this->partialMock(CustomerLogin::class);
        $loginMock->shouldReceive('verify')->once()->with('otp-chal-1', '123456')->andReturn($account);

        $this->withSession([
            'customer_login_phone' => '09121112233',
            'customer_challenge' => 'otp-chal-1',
        ]);
        $flow = CustomerLoginFlow::token(request());
        $this->withSession(['customer_login_flow' => $flow]);

        $this->post(route('account.verify'), [
            'flow' => $flow,
            'challenge' => 'otp-chal-1',
            'code' => '۱۲۳۴۵۶',
        ])->assertRedirect();

        $this->assertSame([
            'me31-merge' => 5,
            's910-merge' => 2,
        ], $this->get(route('cart.index'))->assertOk()->viewData('quantity'));
    }

    public function test_city_spelling_two_stage_repair_otp_and_mobile_confirmation_stepper(): void
    {
        $tehranCities = config('iran_locations.تهران');
        $this->assertContains('اسلامشهر', $tehranCities);
        $this->assertNotContains('اسلام‌شهر', $tehranCities);

        // Guest /repair Step 4 shows two-stage review CTA without pre-opening OTP box
        $repairPage = $this->get(route('repair'))->assertOk();
        $repairPage->assertSee('تأیید اطلاعات و دریافت کد پیامکی');
        $repairPage->assertSee('autocomplete="one-time-code"', false);
        $repairPage->assertSee('repair-inline-auth__step--request', false);

        // /repair/confirmation renders compact 4-column horizontal stepper
        $confirmPage = $this->withSession([
            'repair_reference' => '91827364',
            'repair_handover' => 'self_ship',
        ])->get(route('repair.confirmation'))->assertOk();

        $confirmPage->assertSee('repair-confirm-progress', false);
        $confirmPage->assertSeeInOrder([
            'ثبت درخواست',
            'دریافت دستگاه',
            'بررسی و اعلام هزینه',
            'تعمیر و تحویل',
        ]);
        $confirmPage->assertSee('وضعیت فعلی:');
        $confirmPage->assertSee('دستگاه هنوز تحویل نشده است');
    }
}
