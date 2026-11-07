<?php

namespace Tests\Feature\Admin;

use App\Enums\RepairBrand;
use App\Models\RepairCase;
use App\Models\RepairRecordRevision;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SimpleRepairTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $token = bin2hex(random_bytes(32));
        $this->withSession(['repair_intake_token' => $token]);

        return ['simple_intake' => '1', 'submission_token' => $token, 'customer_name' => 'مشتری آزمایشی', 'contact_phone' => '۰۹۰۰۰۰۰۰۰۰۰', 'device_model' => 'دستگاه آزمایشی', 'device_serial' => '', 'issue' => 'روشن نمی‌شود', 'physical_condition' => 'خط روی بدنه'];
    }

    public function test_compact_intake_requires_only_six_fields_and_records_time_automatically(): void
    {
        $this->freezeTime();
        $this->post('/admin/repairs/intake', $this->payload())->assertRedirect();
        $case = RepairCase::query()->sole();
        $this->assertTrue($case->simple_workflow);
        $this->assertNull($case->device_brand);
        $this->assertNull($case->assigned_to);
        $this->assertNull($case->device_serial);
        $this->assertSame('09000000000', $case->contact_phone);
        $this->assertSame(now()->getTimestamp(), $case->received_at->getTimestamp());
        $this->assertMatchesRegularExpression('/\A[0-9]{8}\z/D', $case->tracking_reference);
        $this->assertDatabaseHas('repair_tracking_codes', ['code' => $case->tracking_reference]);
        $this->assertNotSame($case->contact_phone, DB::table('repair_cases')->value('contact_phone'));
        $this->get(route('admin.repair-cases.show', $case))->assertOk();
    }

    public function test_compact_intake_cannot_inject_manual_time_assignment_or_shipping(): void
    {
        $data = $this->payload() + ['received_at' => '2026-01-01T10:00', 'technician_id' => 1, 'inbound_shipping_cost' => 100];
        $this->post('/admin/repairs/intake', $data)->assertRedirect()->assertSessionHasErrors(['received_at', 'technician_id', 'inbound_shipping_cost']);
        $this->assertDatabaseCount('repair_cases', 0);
        $this->assertDatabaseCount('repair_tracking_codes', 0);
    }

    public function test_tracking_code_alone_does_not_disclose_a_repair(): void
    {
        $this->post('/admin/repairs/intake', $this->payload())->assertRedirect();
        $case = RepairCase::query()->sole();
        $this->post('/repair/track', ['tracking_reference' => $case->tracking_reference, 'contact_phone' => '09000000001'])->assertOk()->assertDontSee('مشتری آزمایشی')->assertDontSee('خط روی بدنه');
        $this->post('/repair/track', ['tracking_reference' => $case->tracking_reference, 'contact_phone' => '09000000000'])->assertOk()->assertViewHas('physicalMatch', true)->assertDontSee('خط روی بدنه');
    }

    public function test_repair_needs_no_assignment_diagnosis_estimate_or_customer_decision(): void
    {
        $this->post('/admin/repairs/intake', $this->payload())->assertRedirect();
        $case = RepairCase::query()->sole();
        foreach (['repairing', 'ready'] as $status) {
            $this->post(route('admin.repair-cases.record', $case), ['version' => $case->version, 'status' => $status, 'repair_cost' => '۳۰۰', 'money_scale' => '1000', 'delivery_method' => 'in_person', 'repair_notes' => 'یادداشت خصوصی'])->assertRedirect()->assertSessionHasNoErrors();
            $case->refresh();
            $this->assertSame($status, $case->status->value);
            $this->assertSame(300000, $case->repair_price_toman);
        }
        $this->assertSame(300000, $case->billableToman());
        foreach (['repair_inspections', 'repair_estimates', 'repair_work_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('repair_record_revisions', 2);
        $this->assertStringNotContainsString('یادداشت خصوصی', DB::table('repair_record_revisions')->value('payload'));
        $this->get(route('admin.repair-cases.show', $case))->assertOk()->assertSee('تسویه شد')->assertDontSee('technician_id')->assertDontSee('name="service_cost"', false);
    }

    public function test_settlement_checkbox_cannot_create_or_fake_a_payment(): void
    {
        $this->post('/admin/repairs/intake', $this->payload())->assertRedirect();
        $case = RepairCase::query()->sole();
        $this->post(route('admin.repair-cases.record', $case), ['version' => 1, 'status' => 'ready', 'repair_cost' => '300', 'money_scale' => '1000', 'settled' => '1', 'settlement_method' => 'online', 'delivery_method' => 'in_person'])->assertStatus(409);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('financial_entries', 0);
        $this->assertSame('received', $case->fresh()->status->value);
    }

    public function test_exact_amounts_and_concurrent_updates_remain_safe(): void
    {
        $this->post('/admin/repairs/intake', $this->payload())->assertRedirect();
        $case = RepairCase::query()->sole();
        $data = ['version' => 1, 'status' => 'ready', 'repair_cost' => '۱۲۳٬۴۵۶', 'money_scale' => '1', 'delivery_method' => 'shipping', 'delivery_carrier' => 'tipax'];
        $this->post(route('admin.repair-cases.record', $case), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(123456, $case->fresh()->repair_price_toman);
        $this->post(route('admin.repair-cases.record', $case), $data)->assertStatus(409);
        $data['version'] = 2;
        $data['status'] = 'delivered';
        $this->post(route('admin.repair-cases.record', $case), $data)->assertStatus(409);
        $this->assertDatabaseCount('repair_deliveries', 0);
    }

    public function test_name_and_model_search_keeps_search_state_private(): void
    {
        $this->post('/admin/repairs/intake', $this->payload())->assertRedirect();
        $this->post('/admin/repairs/search', ['q' => 'مشتری آزمایشی'])->assertRedirect();
        $this->get('/admin/repairs')->assertOk()->assertSee('دستگاه آزمایشی');
        $this->assertStringNotContainsString('مشتری آزمایشی', json_encode(session('repair_search'), JSON_UNESCAPED_UNICODE));
    }

    public function test_history_is_hidden_and_database_immutable(): void
    {
        $this->post('/admin/repairs/intake', $this->payload())->assertRedirect();
        $case = RepairCase::query()->sole();
        $this->post(route('admin.repair-cases.record', $case), ['version' => 1, 'status' => 'ready', 'repair_cost' => '300', 'money_scale' => '1000', 'delivery_method' => 'in_person'])->assertRedirect();
        $this->get(route('admin.repair-cases.document', [$case, 'summary']))->assertOk()->assertSee('۳۰۰٬۰۰۰');
        $revision = RepairRecordRevision::query()->sole();
        $this->assertArrayNotHasKey('payload', $revision->toArray());
        $this->expectException(QueryException::class);
        DB::table('repair_record_revisions')->where('id', $revision->id)->update(['version' => 10]);
    }

    public function test_unknown_brand_serial_cannot_duplicate_an_active_legacy_device(): void
    {
        $data = $this->payload();
        $data['device_serial'] = 'SERIAL-ONE';
        $this->post('/admin/repairs/intake', $data)->assertRedirect();
        $case = RepairCase::query()->sole();
        // Existing branded history keeps its own identifier; no historical rewrite is needed.
        $case->device_brand = RepairBrand::cases()[0];
        $case->save();
        $data['submission_token'] = bin2hex(random_bytes(32));
        $this->withSession(['repair_intake_token' => $data['submission_token']]);
        $data['device_serial'] = 'serial one';
        $this->post('/admin/repairs/intake', $data)->assertStatus(409);
        $this->assertDatabaseCount('repair_cases', 1);
        $this->assertDatabaseCount('repair_tracking_codes', 1);
    }
}
