<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAccount;
use App\Models\AdminActivity;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderEvent;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CommerceManagement;
use App\Support\OrderMoney;
use App\Support\RepairIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrdersInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->superAdmin()->create();
        $this->actingAs($this->actor);
        $this->customer = new Customer;
        foreach (['public_id' => (string) Str::uuid(), 'name' => 'PRIVATE-ORDER-CUSTOMER', 'contact_phone' => '09000000001', 'name_digest' => RepairIdentity::digest('customer-name', 'PRIVATE-ORDER-CUSTOMER'), 'phone_digest' => RepairIdentity::digest('customer-phone', '09000000001'), 'identity_digest' => bin2hex(random_bytes(32)), 'is_active' => true] as $key => $value) {
            $this->customer->$key = $value;
        }
        $this->customer->save();
    }

    private function command(string $action, array $data, InventoryItem|SalesOrder|null $target = null): int
    {
        return app(CommerceManagement::class)->execute($this->actor, $action, ['operation_key' => (string) Str::uuid()] + ($target ? ['version' => $target->fresh()->version] : []) + $data, $target);
    }

    private function item(bool $serialized = false, string $condition = 'new'): InventoryItem
    {
        return InventoryItem::findOrFail($this->command('item.create', ['sku' => 'SKU-'.Str::upper(Str::random(8)), 'name' => 'کالای آزمایشی', 'condition' => $condition, 'serialized' => $serialized, 'low_stock_threshold' => 2, 'reason' => 'Synthetic approved item']));
    }

    private function receive(InventoryItem $item, int $quantity, string $serials = ''): void
    {
        $this->command('item.receive', compact('quantity', 'serials') + ['reason' => 'Synthetic stock receipt'], $item);
    }

    private function order(InventoryItem $item, int $quantity = 1): SalesOrder
    {
        return SalesOrder::findOrFail($this->command('order.save', ['customer' => $this->customer->public_id, 'lines' => [['item' => $item->public_id, 'quantity' => $quantity, 'unit_price' => '123.45']], 'reason' => 'Agreed internal sale']));
    }

    private function transition(SalesOrder $order, string $action, array $extra = []): void
    {
        $this->command('order.'.$action, ['reason' => 'Synthetic operational review'] + $extra, $order);
    }

    private function conflict(callable $callback, int $status = 409): void
    {
        try {
            $callback();
            $this->fail('Expected conflict or denial');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    public function test_catalog_records_are_not_physical_stock_and_are_untouched(): void
    {
        $product = Product::create(['name' => 'Sample', 'slug' => 'sample', 'category' => 'pos_terminal', 'condition' => 'new', 'stock_status' => 'in_stock', 'is_sample' => true, 'is_published' => true]);
        $before = $product->fresh()->getAttributes();
        $new = $this->item();
        $used = $this->item(false, 'used');
        $this->receive($new, 4);
        $this->receive($used, 2);
        $this->assertSame(4, $new->fresh()->available);
        $this->assertSame(2, $used->fresh()->available);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertNull($new->product_id);
    }

    public function test_receive_adjust_and_threshold_are_versioned_and_audited(): void
    {
        $item = $this->item();
        $this->receive($item, 5);
        $this->command('item.adjust', ['quantity' => -2, 'reason' => 'Verified count correction'], $item);
        $this->command('item.configure', ['low_stock_threshold' => 4, 'reason' => 'Updated reorder threshold'], $item);
        $this->assertSame(3, $item->fresh()->on_hand);
        $this->assertSame(4, $item->fresh()->version);
        $this->assertSame(4, StockMovement::count());
        $this->assertSame(4, AdminActivity::count());
        $this->get('/admin/inventory?low=1')->assertOk()->assertSee($item->sku);
        $this->conflict(fn () => $this->command('item.adjust', ['quantity' => -4, 'reason' => 'Would become negative'], $item));
    }

    public function test_full_lifecycle_conserves_bulk_stock_without_payment_side_effects(): void
    {
        $item = $this->item();
        $this->receive($item, 5);
        $order = $this->order($item, 2);
        $this->assertSame('246.90', $order->total);
        $this->assertSame(0, $item->fresh()->reserved);
        $this->transition($order, 'reserve');
        $this->assertSame(5, $item->fresh()->on_hand);
        $this->assertSame(2, $item->fresh()->reserved);
        $this->transition($order, 'confirm');
        $this->assertNull($order->fresh()->fulfilled_at);
        $this->transition($order, 'fulfill', ['fulfillment_info' => 'PRIVATE-HANDOVER-DATA']);
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame([3, 0, 2], [$item->fresh()->on_hand, $item->fresh()->reserved, $item->fresh()->dispatched]);
        $this->assertSame(5, (int) StockMovement::where('inventory_item_id', $item->id)->sum('on_hand_delta') + (int) StockMovement::where('inventory_item_id', $item->id)->sum('dispatched_delta'));
        $this->conflict(fn () => $this->transition($order, 'fulfill', ['fulfillment_info' => 'Duplicate delivery attempt']));
        $this->assertSame(1, StockMovement::where('kind', 'dispatch')->count());
    }

    public function test_release_then_edit_preserves_line_revisions_reservations_and_prices(): void
    {
        $item = $this->item();
        $this->receive($item, 3);
        $order = $this->order($item);
        $this->transition($order, 'reserve');
        $this->transition($order, 'confirm');
        $this->transition($order, 'release');
        $this->command('order.save', ['customer' => $this->customer->public_id, 'lines' => [['item' => $item->public_id, 'quantity' => 2, 'unit_price' => '200.01']], 'reason' => 'Explicit draft price revision'], $order);
        $this->assertSame('400.02', $order->fresh()->total);
        $this->assertSame(2, DB::table('sales_order_lines')->where('sales_order_id', $order->id)->count());
        $this->assertSame(1, DB::table('stock_reservations')->where('status', 'released')->count());
        $first = $order->events()->oldest('id')->first();
        $this->assertSame('123.45', $first->line_snapshot[0]['unit_price']);
        $this->transition($order, 'reserve');
        $this->transition($order, 'cancel');
        $this->assertSame(3, $item->fresh()->available);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_serials_are_globally_unique_and_terminal_serials_cannot_be_reused(): void
    {
        $first = $this->item(true);
        $second = $this->item(true, 'used');
        $this->receive($first, 2, "SER-001\nSER-002");
        $this->conflict(fn () => $this->receive($second, 1, 'ser-001'));
        $order = $this->order($first);
        $this->transition($order, 'reserve');
        $this->command('item.adjust', ['quantity' => -1, 'serials' => 'SER-002', 'reason' => 'Verified defective stock removal'], $first);
        $this->conflict(fn () => $this->command('item.adjust', ['quantity' => -1, 'serials' => 'SER-001', 'reason' => 'Reserved serial cannot leave'], $first));
        $this->transition($order, 'confirm');
        $this->transition($order, 'fulfill', ['fulfillment_info' => 'Synthetic handover receipt']);
        $this->assertDatabaseHas('inventory_units', ['serial' => 'SER-001', 'state' => 'dispatched']);
        $this->assertDatabaseHas('inventory_units', ['serial' => 'SER-002', 'state' => 'removed']);
        $this->conflict(fn () => $this->receive($second, 1, 'SER-002'));
        $this->assertSame(0, $second->fresh()->on_hand);
    }

    public function test_serial_release_and_rereservation_do_not_duplicate_units(): void
    {
        $item = $this->item(true);
        $this->receive($item, 1, 'UNIQUE-01');
        $first = $this->order($item);
        $second = $this->order($item);
        $this->transition($first, 'reserve');
        $this->conflict(fn () => $this->transition($second, 'reserve'));
        $this->transition($first, 'release');
        $this->transition($second, 'reserve');
        $this->assertSame(1, InventoryUnit::where('state', 'reserved')->count());
        $this->assertSame(1, (int) DB::table('stock_reservations')->where('status', 'active')->sum('quantity'));
    }

    public static function idempotentActions(): array
    {
        return [['receive'], ['adjust'], ['reserve'], ['release'], ['confirm'], ['cancel'], ['fulfill']];
    }

    #[DataProvider('idempotentActions')]
    public function test_repeated_commands_do_not_duplicate_writes(string $action): void
    {
        $item = $this->item();
        $this->receive($item, 4);
        $order = $this->order($item);
        if (in_array($action, ['release', 'confirm', 'fulfill'], true)) {
            $this->transition($order, 'reserve');
        }
        if ($action === 'fulfill') {
            $this->transition($order, 'confirm');
        }
        $target = in_array($action, ['receive', 'adjust'], true) ? $item : $order;
        $command = ($target instanceof InventoryItem ? 'item.' : 'order.').$action;
        $data = ['operation_key' => (string) Str::uuid(), 'version' => $target->fresh()->version, 'reason' => 'PRIVATE-REASON-IDEMPOTENCY'];
        if ($target instanceof InventoryItem) {
            $data['quantity'] = 1;
        }
        if ($action === 'fulfill') {
            $data['fulfillment_info'] = 'PRIVATE-FULFILLMENT';
        }
        $first = app(CommerceManagement::class)->execute($this->actor, $command, $data, $target);
        $counts = [StockMovement::count(), SalesOrderEvent::count(), AdminActivity::count()];
        $second = app(CommerceManagement::class)->execute($this->actor, $command, $data, $target);
        $this->assertSame($first, $second);
        $this->assertSame($counts, [StockMovement::count(), SalesOrderEvent::count(), AdminActivity::count()]);
        $this->conflict(fn () => app(CommerceManagement::class)->execute($this->actor, $command, array_replace($data, ['reason' => 'Different reused command']), $target));
    }

    public function test_duplicate_creation_keys_cannot_create_two_items_or_orders(): void
    {
        $key = (string) Str::uuid();
        $data = ['operation_key' => $key, 'sku' => 'RETRY-ITEM', 'name' => 'Retry item', 'condition' => 'new', 'serialized' => false, 'low_stock_threshold' => 0, 'reason' => 'Approved item creation'];
        $management = app(CommerceManagement::class);
        $id = $management->execute($this->actor, 'item.create', $data);
        $this->assertSame($id, $management->execute($this->actor, 'item.create', $data));
        $item = InventoryItem::findOrFail($id);
        $data = ['operation_key' => (string) Str::uuid(), 'customer' => $this->customer->public_id, 'lines' => [['item' => $item->public_id, 'quantity' => 1, 'unit_price' => '0.01']], 'reason' => 'Approved order creation'];
        $id = $management->execute($this->actor, 'order.save', $data);
        $this->assertSame($id, $management->execute($this->actor, 'order.save', $data));
        $this->assertSame(1, SalesOrder::count());
        $this->assertSame(1, InventoryItem::count());
    }

    public function test_stale_versions_and_forged_states_do_not_mutate_stock(): void
    {
        $item = $this->item();
        $this->receive($item, 4);
        $order = $this->order($item);
        $payload = ['operation_key' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Stale write attempt', 'quantity' => 1, 'on_hand' => 9999, 'reserved' => 0, 'version_override' => 999];
        $this->post('/admin/inventory/'.$item->public_id.'/receive', $payload)->assertStatus(409);
        $this->post('/admin/orders/'.$order->public_id.'/reserve', ['operation_key' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Forged status ignored', 'status' => 'fulfilled', 'total' => '0.00', 'customer_id' => 999, 'is_admin' => true])->assertRedirect();
        $this->assertSame('reserved', $order->fresh()->status);
        $this->assertSame('123.45', $order->fresh()->total);
        $this->assertSame(4, $item->fresh()->on_hand);
        $this->conflict(fn () => $this->transition($order, 'reserve'));
        $this->conflict(fn () => $this->transition($order, 'fulfill', ['fulfillment_info' => 'Cannot bypass confirmation']));
    }

    public function test_failed_multi_item_reservation_rolls_back_first_item_and_audits(): void
    {
        $first = $this->item(true);
        $second = $this->item();
        $this->receive($first, 1, 'ROLLBACK-01');
        $order = SalesOrder::findOrFail($this->command('order.save', ['customer' => $this->customer->public_id, 'lines' => [['item' => $first->public_id, 'quantity' => 1, 'unit_price' => '1.01'], ['item' => $second->public_id, 'quantity' => 1, 'unit_price' => '2.02']], 'reason' => 'Multi-line all-or-nothing']));
        $counts = [StockMovement::count(), AdminActivity::count(), DB::table('commerce_operations')->count()];
        $this->conflict(fn () => $this->transition($order, 'reserve'));
        $this->assertSame(0, $first->fresh()->reserved);
        $this->assertDatabaseHas('inventory_units', ['serial' => 'ROLLBACK-01', 'state' => 'on_hand']);
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame($counts, [StockMovement::count(), AdminActivity::count(), DB::table('commerce_operations')->count()]);
    }

    public function test_injected_failure_after_fulfillment_rolls_back_every_domain_write(): void
    {
        $item = $this->item(true);
        $this->receive($item, 1, 'ATOMIC-01');
        $order = $this->order($item);
        $this->transition($order, 'reserve');
        $this->transition($order, 'confirm');
        $counts = [StockMovement::count(), SalesOrderEvent::count(), AdminActivity::count(), DB::table('commerce_operations')->count()];
        Event::listen('commerce.after-write', fn () => throw new LogicException('Injected test failure'));
        try {
            $this->transition($order, 'fulfill', ['fulfillment_info' => 'Synthetic rollback handover']);
            $this->fail('Expected injected failure');
        } catch (LogicException $exception) {
            $this->assertSame('Injected test failure', $exception->getMessage());
        } finally {
            Event::forget('commerce.after-write');
        }
        $this->assertSame([1, 1, 0], [$item->fresh()->on_hand, $item->fresh()->reserved, $item->fresh()->dispatched]);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertNull($order->fresh()->fulfillment_info);
        $this->assertDatabaseHas('inventory_units', ['serial' => 'ATOMIC-01', 'state' => 'reserved']);
        $this->assertSame($counts, [StockMovement::count(), SalesOrderEvent::count(), AdminActivity::count(), DB::table('commerce_operations')->count()]);
    }

    public function test_partial_fulfillment_and_blank_reasons_are_rejected_without_pii_flash(): void
    {
        $item = $this->item();
        $this->receive($item, 2);
        $order = $this->order($item, 2);
        $this->transition($order, 'reserve');
        $this->transition($order, 'confirm');
        $this->post('/admin/orders/'.$order->public_id.'/fulfill', ['operation_key' => (string) Str::uuid(), 'version' => $order->fresh()->version, 'reason' => 'PRIVATE-REASON', 'fulfillment_info' => 'PRIVATE-HANDOVER', 'partial_quantity' => 1])->assertSessionHasErrors('partial_quantity')->assertSessionMissing('_old_input');
        $this->post('/admin/inventory/'.$item->public_id.'/adjust', ['operation_key' => (string) Str::uuid(), 'version' => $item->fresh()->version, 'quantity' => -1, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(2, $item->fresh()->reserved);
    }

    public function test_customer_fulfillment_and_reasons_are_encrypted_and_activity_has_no_payload(): void
    {
        $item = $this->item();
        $this->receive($item, 1);
        $order = $this->order($item);
        $this->transition($order, 'reserve');
        $this->transition($order, 'confirm');
        $this->transition($order, 'fulfill', ['fulfillment_info' => 'PRIVATE-DELIVERY-NOTE']);
        $raw = DB::table('sales_orders')->find($order->id);
        $this->assertStringNotContainsString('PRIVATE-DELIVERY', $raw->fulfillment_info);
        $this->assertStringNotContainsString('operational', DB::table('sales_order_events')->latest('id')->value('reason'));
        $this->assertArrayNotHasKey('fulfillment_info', $order->fresh()->toArray());
        $this->assertStringNotContainsString('PRIVATE', json_encode(AdminActivity::all()->toArray()));
        $this->actingAs(User::factory()->staff(['orders.view'])->create());
        $this->get('/admin/orders/'.$order->public_id)->assertOk()->assertDontSee('PRIVATE-ORDER-CUSTOMER')->assertDontSee('09000000001')->assertDontSee('PRIVATE-DELIVERY-NOTE')->assertDontSee('Synthetic operational review');
        $this->get('/admin/orders')->assertOk()->assertDontSee('PRIVATE-ORDER-CUSTOMER');
    }

    public static function protectedWrites(): array
    {
        return [['post', '/admin/inventory'], ['post', '/admin/inventory/ITEM/receive'], ['post', '/admin/inventory/ITEM/adjust'], ['post', '/admin/inventory/ITEM/configure'], ['post', '/admin/orders'], ['put', '/admin/orders/ORDER'], ['post', '/admin/orders/ORDER/reserve'], ['post', '/admin/orders/ORDER/release'], ['post', '/admin/orders/ORDER/confirm'], ['post', '/admin/orders/ORDER/fulfill'], ['post', '/admin/orders/ORDER/cancel']];
    }

    #[DataProvider('protectedWrites')]
    public function test_view_only_staff_cannot_call_write_endpoints(string $method, string $uri): void
    {
        $item = $this->item();
        $order = $this->order($item);
        $this->actingAs(User::factory()->staff(['inventory.view', 'orders.view'])->create());
        $uri = str_replace(['ITEM', 'ORDER'], [$item->public_id, $order->public_id], $uri);
        $this->$method($uri, ['on_hand' => 999, 'status' => 'fulfilled', 'role' => 'super_admin'])->assertForbidden();
        $this->assertSame(0, $item->fresh()->on_hand);
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_ordinary_user_and_revoked_staff_cannot_read_or_write_modules(): void
    {
        $item = $this->item();
        $order = $this->order($item);
        $this->flushSession();
        $this->actingAs(User::factory()->create());
        foreach (['/admin/inventory', '/admin/orders', '/admin/inventory/'.$item->public_id, '/admin/orders/'.$order->public_id] as $uri) {
            $this->get($uri)->assertForbidden();
        }
        $staff = User::factory()->staff(['inventory.view', 'inventory.receive'])->create();
        $this->actingAs($staff);
        $this->get('/admin/inventory')->assertOk();
        $account = AdminAccount::where('user_id', $staff->id)->firstOrFail();
        $account->permissions = ['inventory.view'];
        $account->save();
        $this->post('/admin/inventory/'.$item->public_id.'/receive', [])->assertForbidden();
        $account->is_active = false;
        $account->save();
        $this->get('/admin/inventory')->assertRedirect(route('admin.login'));
    }

    public function test_service_authorization_default_deny_and_idempotency_actor_binding(): void
    {
        $item = $this->item();
        $this->receive($item, 1);
        $data = ['operation_key' => (string) Str::uuid(), 'version' => $item->fresh()->version, 'quantity' => 1, 'reason' => 'Actor-scoped idempotent command'];
        app(CommerceManagement::class)->execute($this->actor, 'item.receive', $data, $item);
        $staff = User::factory()->staff(['inventory.view', 'inventory.receive'])->create();
        $this->conflict(fn () => app(CommerceManagement::class)->execute($staff, 'item.receive', $data, $item));
        $this->expectException(AuthorizationException::class);
        app(CommerceManagement::class)->execute(User::factory()->staff(['inventory.view'])->create(), 'item.receive', $data, $item);
    }

    public function test_append_only_models_and_no_http_history_mutation(): void
    {
        $item = $this->item();
        $order = $this->order($item);
        $this->delete('/admin/orders/'.$order->public_id)->assertStatus(405);
        $this->delete('/admin/inventory/'.$item->public_id)->assertStatus(405);
        $this->expectException(LogicException::class);
        $movement = StockMovement::firstOrFail();
        $movement->reason = 'Overwrite';
        $movement->save();
    }

    public function test_inactive_or_merged_customer_cannot_receive_new_orders(): void
    {
        $item = $this->item();
        $this->customer->is_active = false;
        $this->customer->save();
        $this->conflict(fn () => $this->order($item));
        $this->assertSame(0, SalesOrder::count());
    }

    public function test_precise_money_and_bounded_inputs(): void
    {
        $this->assertSame(100001, OrderMoney::minor('1000.01'));
        $this->assertSame('0.01', OrderMoney::decimal(1));
        $item = $this->item();
        $payload = ['operation_key' => (string) Str::uuid(), 'customer' => $this->customer->public_id, 'reason' => 'Input constraints check', 'lines' => [['item' => $item->public_id, 'quantity' => 0, 'unit_price' => '1e9']]];
        $this->post('/admin/orders', $payload)->assertSessionHasErrors(['lines.0.quantity', 'lines.0.unit_price']);
        $payload['lines'] = [['item' => $item->public_id, 'quantity' => 1, 'unit_price' => '0.10'], ['item' => $item->public_id, 'quantity' => 2, 'unit_price' => '0.20']];
        $this->post('/admin/orders', $payload)->assertSessionHasErrors('lines.0.item');
    }

    public function test_authorized_routes_render_with_private_headers_and_real_metrics(): void
    {
        $item = $this->item(true);
        $this->receive($item, 1, 'SCREEN-01');
        $order = $this->order($item);
        foreach (['/admin/inventory', '/admin/inventory/create', '/admin/inventory/movements', '/admin/inventory/'.$item->public_id, '/admin/orders', '/admin/orders/create', '/admin/orders/'.$order->public_id, '/admin/orders/'.$order->public_id.'/edit', '/admin'] as $uri) {
            $response = $this->get($uri)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->get('/admin')->assertViewHas('pendingOrderCount', 1)->assertViewHas('lowStockCount', 1);
        $this->get('/admin/inventory?search=serial&q=SCREEN-01')->assertOk()->assertSee($item->sku);
        $this->get('/admin/orders?reference='.(string) Str::uuid())->assertOk()->assertDontSee($order->public_id);
    }

    public function test_catalog_product_referenced_by_inventory_cannot_be_deleted(): void
    {
        $product = Product::create(['name' => 'Physical catalog item', 'slug' => 'physical-catalog-item', 'category' => 'pos_terminal', 'condition' => 'new', 'stock_status' => 'in_stock']);
        $this->command('item.create', ['sku' => 'CATALOG-REF', 'name' => 'Physical stock', 'condition' => 'new', 'serialized' => true, 'product_id' => $product->id, 'low_stock_threshold' => 1, 'reason' => 'Approved catalog association']);
        $this->delete('/admin/products/'.$product->slug)->assertStatus(409);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_receiver_cannot_adjust_and_reserver_cannot_confirm_or_fulfill(): void
    {
        $item = $this->item();
        $this->receive($item, 3);
        $order = $this->order($item);
        $staff = User::factory()->staff(['orders.view', 'orders.reserve'])->create();
        $this->actingAs($staff);
        $data = ['operation_key' => (string) Str::uuid(), 'version' => $order->version, 'reason' => 'Permitted stock reservation'];
        $this->post('/admin/orders/'.$order->public_id.'/reserve', $data)->assertRedirect();
        $this->post('/admin/orders/'.$order->public_id.'/confirm', [])->assertForbidden();
        $this->post('/admin/orders/'.$order->public_id.'/fulfill', [])->assertForbidden();
        $this->actingAs(User::factory()->staff(['inventory.view', 'inventory.receive'])->create());
        $this->post('/admin/inventory/'.$item->public_id.'/adjust', [])->assertForbidden();
        $this->post('/admin/inventory/'.$item->public_id.'/receive', ['operation_key' => (string) Str::uuid(), 'version' => $item->fresh()->version, 'quantity' => 1, 'reason' => 'Permitted additional receipt'])->assertRedirect();
    }

    public function test_contact_permission_alone_does_not_reveal_customer_or_enable_fulfillment(): void
    {
        $item = $this->item();
        $order = $this->order($item);
        $this->actingAs(User::factory()->staff(['orders.view', 'orders.customer_data'])->create());
        $this->get('/admin/orders/'.$order->public_id)->assertOk()->assertDontSee('PRIVATE-ORDER-CUSTOMER')->assertDontSee('09000000001');
        $this->get('/admin/orders/create')->assertForbidden();
        $this->actingAs(User::factory()->staff(['orders.view', 'orders.customer_data', 'customers.view', 'customers.contact'])->create());
        $this->get('/admin/orders/'.$order->public_id)->assertOk()->assertSee('PRIVATE-ORDER-CUSTOMER')->assertSee('09000000001');
    }

    public function test_low_stock_flag_reconciles_after_receipt_reservation_release_and_adjustment(): void
    {
        $item = $this->item();
        $this->receive($item, 3);
        $this->assertFalse((bool) $item->fresh()->is_low_stock);
        $order = $this->order($item);
        $this->transition($order, 'reserve');
        $this->assertTrue((bool) $item->fresh()->is_low_stock);
        $this->transition($order, 'release');
        $this->assertFalse((bool) $item->fresh()->is_low_stock);
        $this->command('item.adjust', ['quantity' => -1, 'reason' => 'Verified count correction'], $item);
        $this->assertTrue((bool) $item->fresh()->is_low_stock);
        $this->command('item.configure', ['low_stock_threshold' => 0, 'reason' => 'New reorder threshold'], $item);
        $this->assertFalse((bool) $item->fresh()->is_low_stock);
    }

    public function test_unknown_commands_default_deny_before_any_write(): void
    {
        $before = DB::table('commerce_operations')->count();
        $this->conflict(fn () => app(CommerceManagement::class)->execute($this->actor, 'order.force_fulfill', []), 403);
        $this->assertSame($before, DB::table('commerce_operations')->count());
    }

    public function test_new_permission_dependencies_prevent_incomplete_staff_grants(): void
    {
        $owner = $this->actor;
        $owner->password = 'Owner-Phase6C-Test';
        $owner->save();
        foreach (['inventory.receive', 'inventory.adjust', 'orders.create', 'orders.fulfill', 'orders.customer_data'] as $permission) {
            $this->post('/admin/staff', ['name' => 'Synthetic staff', 'email' => 'grant@example.test', 'password' => 'Initial-Phase6C-Test', 'password_confirmation' => 'Initial-Phase6C-Test', 'current_password' => 'Owner-Phase6C-Test', 'is_active' => '1', 'permissions' => [$permission]])->assertSessionHasErrors('permissions');
        }
        $this->assertSame(1, AdminAccount::count());
    }

    public function test_paginated_lists_do_not_add_queries_per_row_or_replay_unknown_parameters(): void
    {
        $this->item();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/admin/inventory')->assertOk();
        $before = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 25; $i++) {
            $this->item();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/admin/inventory?token=PRIVATE-NOT-A-FILTER')->assertOk()->assertDontSee('PRIVATE-NOT-A-FILTER');
        $after = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($before, $after, 'Inventory query count must remain constant with page size.');
    }

    public function test_inventory_configuration_does_not_grant_unpublished_catalog_access(): void
    {
        $product = Product::create(['name' => 'PRIVATE-UNPUBLISHED-CATALOG', 'slug' => 'private-catalog', 'category' => 'pos_terminal', 'condition' => 'new', 'stock_status' => 'in_stock', 'is_published' => false]);
        $staff = User::factory()->staff(['inventory.view', 'inventory.configure'])->create();
        $this->actingAs($staff);
        $this->get('/admin/inventory/create')->assertOk()->assertDontSee('PRIVATE-UNPUBLISHED-CATALOG');
        $data = ['operation_key' => (string) Str::uuid(), 'sku' => 'PRIVATE-CATALOG-LINK', 'name' => 'Approved stock', 'condition' => 'new', 'serialized' => false, 'product_id' => $product->id, 'low_stock_threshold' => 0, 'reason' => 'Attempted catalog association'];
        $this->post('/admin/inventory', $data)->assertForbidden();
        $this->assertSame(0, InventoryItem::count());
        $this->expectException(AuthorizationException::class);
        app(CommerceManagement::class)->execute($staff, 'item.create', $data);
    }
}
