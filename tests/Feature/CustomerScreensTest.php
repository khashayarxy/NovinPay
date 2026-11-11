<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\FinancialEntry;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use App\Models\SalesOrderEvent;
use App\Models\User;
use App\Services\FinancialOperations;
use App\Services\RepairOperations;
use App\Support\CustomerTimeline;
use App\Support\RepairIdentity;
use App\Support\RepairTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_staff_screens_render_using_only_synthetic_data(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->superAdmin()->create(['name' => 'همکار آزمایشی']);
        $this->actingAs($owner, 'web');
        $customer = new Customer;
        foreach (['public_id' => (string) Str::uuid(), 'name' => 'مشتری آزمایشی', 'contact_phone' => '09000000000', 'phone_digest' => RepairIdentity::digest('customer-phone', '09000000000'), 'name_digest' => hash('sha256', 'test-name'), 'identity_digest' => hash('sha256', 'test-identity'), 'is_active' => true] as $key => $value) {
            $customer->$key = $value;
        }
        $customer->save();
        $account = new CustomerAccount;
        foreach (['public_id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'name' => 'مشتری آزمایشی', 'phone' => '09000000000', 'phone_digest' => RepairIdentity::digest('customer-account-phone', '09000000000'), 'verified_at' => now()] as $key => $value) {
            $account->$key = $value;
        }
        $account->save();
        $order = new SalesOrder;
        foreach (['public_id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'created_by' => $owner->id, 'status' => 'fulfilled', 'currency' => 'TOMAN', 'total' => '100000.00', 'version' => 1] as $key => $value) {
            $order->$key = $value;
        }
        $order->save();
        $entry = app(FinancialOperations::class)->execute($owner, 'issue', ['operation_key' => (string) Str::uuid(), 'password' => 'password', 'reason' => 'Synthetic UI fixture only', 'source_type' => 'order', 'source' => $order->public_id, 'source_version' => 1]);
        $document = FinancialDocument::findOrFail(FinancialEntry::findOrFail($entry)->financial_document_id);
        $plan = new DeliveryPlan;
        foreach (['public_id' => (string) Str::uuid(), 'financial_document_id' => $document->id, 'customer_account_id' => $account->id, 'method' => 'tipax'] as $key => $value) {
            $plan->$key = $value;
        }
        $plan->save();
        $case = app(RepairOperations::class)->intake($owner, ['submission_token' => bin2hex(random_bytes(32)), 'customer_name' => 'مشتری آزمایشی', 'contact_phone' => '09000000000', 'device_brand' => 'PAX', 'device_model' => 'مدل آزمایشی', 'issue' => 'مشکل آزمایشی چاپ رسید دستگاه', 'physical_condition' => 'بدون آسیب ظاهری', 'accessories' => 'شارژر', 'received_at' => RepairTime::input(), 'physical_receipt_confirmed' => true]);
        $online = new RepairRequest;
        $online->forceFill([
            'tracking_reference' => '31415926', 'submission_digest' => bin2hex(random_bytes(32)),
            'customer_id' => $customer->id, 'customer_name' => 'مشتری آزمایشی با نام خانوادگی طولانی',
            'contact_phone' => '09000000000', 'phone_digest' => RepairIdentity::digest('phone', '09000000000'),
            'device_brand' => 'PAX', 'device_model' => 'PAX-A920-PRO-SYNTHETIC-LONG-DEVICE-IDENTIFIER',
            'issue' => 'مشکل آزمایشی چاپ رسید دستگاه و بررسی وضعیت اتصال به شبکه', 'status' => 'online_submitted',
        ])->save();
        $this->capture('online-repair-admin', '/admin/repair-requests/'.$online->id);
        $this->capture('repair-intake-admin', '/admin/repairs/intake');
        $this->get(route('admin.customers.show', $customer))->assertOk()->assertSee($account->public_id);
        $limited = User::factory()->staff(['customers.view', 'customers.contact'])->create();
        $this->actingAs($limited, 'web')->get(route('admin.customers.show', $customer))->assertOk()->assertDontSee($account->public_id);
        $this->actingAs($owner, 'web');
        $this->capture('repair-admin', '/admin/repairs/'.$case->public_id);
        $this->capture('payments-admin', '/admin/payments');
        $this->capture('delivery-admin', '/admin/deliveries');
        $this->capture('customer-login', '/login');
        $this->get('/account')->assertRedirect(route('account.login'))->assertDontSee($account->public_id);
        $this->actingAs($account->fresh(), 'customer')->withSession(['customer_session_version' => 1]);
        $this->capture('customer-account', '/account');
        $this->capture('customer-profile', '/account?section=profile');
        $this->get('/account?section=profile')->assertOk()->assertDontSee($account->public_id);
        $this->get('/account')->assertOk()->assertSee($account->public_id)->assertSee('کپی شناسه');
        $this->capture('customer-addresses', '/account?section=addresses');
        $this->capture('customer-order', '/account/orders/'.$order->public_id);
        $this->capture('customer-payment', '/account/documents/'.$document->public_id);
        $this->capture('customer-delivery', '/account/documents/'.$document->public_id.'/delivery');
        $this->capture('customer-repair', '/repair');
        $other = new CustomerAccount;
        $other->forceFill([
            'public_id' => (string) Str::uuid(), 'phone' => '09000000001',
            'phone_digest' => RepairIdentity::digest('customer-account-phone', '09000000001'), 'verified_at' => now(),
        ])->save();
        $this->actingAs($other->fresh(), 'customer')->withSession(['customer_session_version' => 1]);
        $this->get('/account/orders/'.$order->public_id)->assertNotFound();
        $this->get('/account?section=orders')->assertOk()->assertDontSee($order->public_id);
        $this->get('/account?account='.$account->public_id)->assertOk()->assertSee($other->public_id)->assertDontSee($account->public_id);
    }

    public function test_order_timeline_marks_only_recorded_phases_and_does_not_invent_delivery(): void
    {
        $order = new SalesOrder;
        $order->status = 'fulfilled';
        $order->created_at = now()->subDay();
        $order->fulfilled_at = now();
        $event = new SalesOrderEvent;
        $event->to_status = 'confirmed';
        $event->created_at = now()->subHour();
        $order->setRelation('events', collect([$event]));
        $plan = new DeliveryPlan;
        $plan->state = 'dispatched';
        $steps = CustomerTimeline::order($order, $plan);
        $this->assertSame(['complete', 'unrecorded', 'complete', 'current'], array_column($steps, 'state'));
        $this->assertSame('ارسال به شرکت حمل ثبت شد', $steps[3]['label']);

        $order->status = 'cancelled';
        $this->assertSame(['سفارش ثبت شد', 'سفارش تأیید شد', 'سفارش لغو شد'], array_column(CustomerTimeline::order($order, null), 'label'));
    }

    private function capture(string $name, string $url): void
    {
        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('dir="rtl"', $response->getContent());
        if ($directory = getenv('NOVINPAY_QA_DIR')) {
            $this->assertTrue(is_dir($directory) && ! is_link($directory));
            file_put_contents($directory.DIRECTORY_SEPARATOR.$name.'.html', $response->getContent());
        }
    }
}
