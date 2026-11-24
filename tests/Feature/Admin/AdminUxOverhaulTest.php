<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminAction;
use App\Enums\RepairBrand;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\FinancialEntry;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\CommerceManagement;
use App\Services\FinancialOperations;
use App\Services\PaymentReview;
use App\Support\AdminHistory;
use App\Support\DeliveryAddress;
use App\Support\FinanceMoney;
use App\Support\LatinText;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUxOverhaulTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_address_and_money_and_latin_helpers(): void
    {
        $this->assertSame(
            'تهران، باغستان، خیابان اصلی',
            DeliveryAddress::format([
                'province' => 'تهران',
                'city' => 'باغستان',
                'address' => 'باغستان، خیابان اصلی',
            ])
        );
        $this->assertSame(
            'تهران، ولیعصر',
            DeliveryAddress::format([
                'province' => 'تهران',
                'city' => 'تهران',
                'address' => 'تهران، ولیعصر',
            ])
        );

        $this->assertSame('۸۵٬۰۰۰', FinanceMoney::persianToman(8500000));
        $this->assertSame('۸۴٬۳۴۰٬۰۰۰', FinanceMoney::persianToman(8434000000));
        $this->assertSame('۸۵٬۰۰۰', FinanceMoney::persianOrderToman('85000.00'));

        $html = LatinText::html('دستگاه <Newland ME31 Black> مدل PAX Z9')->toHtml();
        $this->assertStringContainsString('&lt;<bdi class="np-latin" dir="ltr">Newland ME31 Black</bdi>&gt;', $html);
        $this->assertStringContainsString('<bdi class="np-latin" dir="ltr">PAX Z9</bdi>', $html);
    }

    public function test_dashboard_activity_and_online_repair_requests_show_human_readable_details(): void
    {
        $owner = User::factory()->superAdmin()->create(['name' => 'خشایار مدیر']);
        $product = Product::create([
            'name' => 'کارتخوان Newland ME31 Black',
            'slug' => 'newland-me31-black',
            'category' => 'pos_terminal',
            'condition' => 'new',
            'stock_status' => 'in_stock',
            'is_published' => true,
        ]);

        $this->actingAs($owner);
        AdminHistory::record(AdminAction::ProductCreated, 'product', $product->id, $owner->id);

        $repair = new RepairRequest;
        $repair->tracking_reference = '92991001';
        $repair->submission_digest = bin2hex(random_bytes(32));
        $repair->customer_name = 'مریم احمدی';
        $repair->contact_phone = '09123334455';
        $repair->phone_digest = RepairIdentity::digest('phone', '09123334455');
        $repair->device_brand = RepairBrand::Newland;
        $repair->device_model = 'ME31';
        $repair->issue = "خرابی شارژ\nنحوه تحویل دستگاه: حضوری میآورم";
        $repair->status = 'online_submitted';
        $repair->save();

        $this->get('/admin')->assertOk()
            ->assertSee('خشایار مدیر')
            ->assertSee('Newland ME31 Black')
            ->assertDontSee('کاربر ۱')
            ->assertDontSee('ثبت آنلاین؛ دریافت دستگاه تأیید نشده است')
            ->assertSee('مریم احمدی')
            ->assertSee('حضوری');

        $this->get('/admin/repair-requests?status=pending&q=احمدی')->assertOk()
            ->assertSee('مریم احمدی')
            ->assertSee('92991001')
            ->assertSee('حضوری');

        $this->get('/admin/repair-requests?status=accepted')->assertOk()
            ->assertDontSee('مریم احمدی');
    }

    public function test_orders_and_customers_partial_search_and_no_raw_uuids_or_decimal_zeros(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $this->actingAs($owner);

        $customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'زهرا کریمی',
            'contact_phone' => '09125556677',
            'name_digest' => RepairIdentity::digest('customer-name', 'زهرا کریمی'),
            'phone_digest' => RepairIdentity::digest('customer-phone', '09125556677'),
            'identity_digest' => RepairIdentity::digest('customer-identity', 'زهرا کریمی|09125556677'),
            'is_active' => true,
            'version' => 1,
        ]);

        $order = SalesOrder::forceCreate([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'created_by' => $owner->id,
            'checkout_key' => (string) Str::uuid(),
            'checkout_digest' => bin2hex(random_bytes(32)),
            'status' => 'confirmed',
            'currency' => 'TOMAN',
            'total' => '85000.00',
            'version' => 1,
        ]);

        $this->get('/admin/orders?q=کریمی')->assertOk()
            ->assertSee('زهرا کریمی')
            ->assertSee('مبلغ سفارش (تومان)')
            ->assertSee('۸۵٬۰۰۰')
            ->assertDontSee('۸۵٬۰۰۰.۰۰')
            ->assertDontSee('<small class="crm-code">'.$order->public_id.'</small>', false);

        $this->post('/admin/customers/search', ['q' => 'کریمی'])->assertRedirect();
        $this->assertStringNotContainsString('کریمی', (string) json_encode(session('customer_search')));
        $this->get('/admin/customers')->assertOk()
            ->assertSee('زهرا کریمی')
            ->assertDontSee('<small class="crm-code">'.$customer->public_id.'</small>', false);
    }

    public function test_maintenance_and_routine_fulfillment_without_password_prompt(): void
    {
        $owner = User::factory()->superAdmin()->create(['password' => 'OwnerPass123!']);
        $this->actingAs($owner);

        $this->get('/admin/maintenance')->assertOk()
            ->assertSee('سایت آنلاین است (حالت نگهداری خاموش)')
            ->assertDontSee('<details class="admin-panel mt-3">', false);

        $account = CustomerAccount::forceCreate([
            'public_id' => (string) Str::uuid(),
            'phone' => '09120001122',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09120001122'),
            'name' => 'رضا محمدی',
            'session_version' => 1,
        ]);
        $customer = Customer::forceCreate([
            'public_id' => (string) Str::uuid(),
            'name' => 'رضا محمدی',
            'contact_phone' => '09120001122',
            'name_digest' => RepairIdentity::digest('customer-name', 'رضا محمدی'),
            'phone_digest' => RepairIdentity::digest('customer-phone', '09120001122'),
            'identity_digest' => RepairIdentity::digest('customer-identity', 'رضا محمدی|09120001122'),
            'is_active' => true,
            'version' => 1,
        ]);
        $commerce = app(CommerceManagement::class);
        $itemId = $commerce->execute($owner, 'item.create', [
            'operation_key' => (string) Str::uuid(),
            'sku' => 'TEST-POS-01',
            'name' => 'کارتخوان تست',
            'condition' => 'new',
            'serialized' => false,
            'low_stock_threshold' => 1,
            'reason' => 'تعریف کالای تست',
        ]);
        $item = InventoryItem::findOrFail($itemId);
        $commerce->execute($owner, 'item.receive', [
            'operation_key' => (string) Str::uuid(),
            'version' => $item->version,
            'quantity' => 5,
            'reason' => 'دریافت اولیه تست',
        ], $item);
        $orderId = $commerce->execute($owner, 'order.save', [
            'operation_key' => (string) Str::uuid(),
            'customer' => $customer->public_id,
            'reason' => 'سفارش تست تحویل',
            'lines' => [
                ['item' => $item->public_id, 'quantity' => 1, 'unit_price' => '50000.00'],
            ],
        ]);
        $order = SalesOrder::findOrFail($orderId);
        $order->forceFill(['customer_account_id' => $account->id])->save();
        $commerce->execute($owner, 'order.reserve', [
            'operation_key' => (string) Str::uuid(),
            'version' => $order->fresh()->version,
            'reason' => 'رزرو تست',
        ], $order->fresh());
        $commerce->execute($owner, 'order.confirm', [
            'operation_key' => (string) Str::uuid(),
            'version' => $order->fresh()->version,
            'reason' => 'تأیید تست',
        ], $order->fresh());

        $entryId = app(FinancialOperations::class)->execute($owner, 'issue', [
            'operation_key' => (string) Str::uuid(),
            'password' => 'OwnerPass123!',
            'reason' => 'صدور سند آزمایشی برای تست تحویل',
            'source_type' => 'order',
            'source' => $order->public_id,
            'source_version' => $order->fresh()->version,
        ]);
        $document = FinancialDocument::findOrFail(FinancialEntry::findOrFail($entryId)->financial_document_id);
        app(PaymentReview::class)->inStore($owner, 'OwnerPass123!', $document->public_id, 5000000, (string) Str::uuid());
        $plan = DeliveryPlan::forceCreate([
            'public_id' => (string) Str::uuid(),
            'financial_document_id' => $document->id,
            'customer_account_id' => $account->id,
            'method' => 'tipax',
            'requested_method' => 'courier',
            'requested_address' => [
                'recipient' => 'رضا محمدی',
                'phone' => '09120001122',
                'province' => 'تهران',
                'city' => 'باغستان',
                'address' => 'باغستان، بلوار امام',
                'postal_code' => '3359111111',
            ],
            'state' => 'planned',
            'version' => 1,
        ]);

        $this->get('/admin/deliveries')->assertOk()
            ->assertSee('رضا محمدی')
            ->assertSee('تهران، باغستان، بلوار امام')
            ->assertDontSee('تهران، باغستان، باغستان')
            ->assertDontSee('name="password"', false);

        $this->post(route('admin.deliveries.confirm', $plan->public_id), [
            'version' => 1,
            'operation_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $this->assertSame('courier', $plan->fresh()->method);

        $this->post(route('admin.deliveries.dispatch', $plan->public_id), [
            'version' => $plan->fresh()->version,
            'recipient' => 'رضا محمدی',
            'tracking' => 'PEYK-102030',
            'handover_confirmed' => '1',
        ])->assertRedirect();

        $this->assertSame('dispatched', $plan->fresh()->state);
        $this->assertSame('fulfilled', $order->fresh()->status);
    }
}
