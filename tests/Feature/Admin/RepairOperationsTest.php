<?php

namespace Tests\Feature\Admin;

use App\Enums\RepairStage;
use App\Models\AdminAccount;
use App\Models\RepairCase;
use App\Models\RepairEstimate;
use App\Models\RepairRequest;
use App\Models\User;
use App\Support\RepairIdentity;
use App\Support\RepairTime;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepairOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->superAdmin()->create();
        $this->actingAs($owner);

        return $owner;
    }

    private function requestRecord(): RepairRequest
    {
        $request = new RepairRequest;
        foreach (['customer_name' => 'PRIVATE-CUSTOMER', 'contact_phone' => '09000000000', 'device_brand' => 'PAX', 'device_model' => 'PRIVATE-MODEL', 'issue' => 'PRIVATE-ISSUE-TEXT'] as $key => $value) {
            $request->$key = $value;
        }
        $request->tracking_reference = 'NP-'.strtoupper(bin2hex(random_bytes(16)));
        $request->submission_digest = bin2hex(random_bytes(32));
        $request->phone_digest = RepairIdentity::digest('phone', $request->contact_phone);
        $request->status = 'online_submitted';
        $request->save();

        return $request;
    }

    private function intakePayload(array $extra = []): array
    {
        $token = bin2hex(random_bytes(32));
        $this->withSession(['repair_intake_token' => $token]);

        return array_replace(['submission_token' => $token, 'customer_name' => 'PRIVATE-CUSTOMER', 'contact_phone' => '09000000000', 'device_brand' => 'PAX', 'device_model' => 'PRIVATE-MODEL', 'device_serial' => 'SERIAL-001', 'issue' => 'PRIVATE-ISSUE-TEXT', 'physical_condition' => 'PRIVATE-CONDITION', 'accessories' => 'PRIVATE-ACCESSORIES', 'received_at' => RepairTime::input(now()->subHour()), 'physical_receipt_confirmed' => '1'], $extra);
    }

    private function intake(array $extra = []): RepairCase
    {
        $this->post('/admin/repairs/intake', $this->intakePayload($extra))->assertRedirect();

        return RepairCase::query()->latest('id')->firstOrFail();
    }

    private function op(RepairCase $case, string $operation, array $data = [])
    {
        return $this->post('/admin/repairs/'.$case->public_id.'/'.$operation, ['version' => $case->fresh()->version] + $data);
    }

    private function inspected(User $owner): RepairCase
    {
        $case = $this->intake();
        $this->op($case, 'assign', ['technician_id' => $owner->id])->assertRedirect();
        $this->op($case, 'transition', ['status' => 'waiting_inspection'])->assertRedirect();
        $this->op($case, 'transition', ['status' => 'inspecting'])->assertRedirect();
        $this->op($case, 'inspect', $this->inspection())->assertRedirect();

        return $case->fresh();
    }

    private function inspection(): array
    {
        return ['findings' => 'PRIVATE-FINDINGS', 'diagnosis' => 'PRIVATE-DIAGNOSIS', 'proposed_actions' => 'PRIVATE-PROPOSAL', 'internal_notes' => 'SECRET-INTERNAL', 'complete' => '1'];
    }

    private function estimate(RepairCase $case, int $cost = 120000): RepairEstimate
    {
        $this->op($case, 'estimate', ['customer_summary' => 'PRIVATE-ESTIMATE-SUMMARY', 'service_cost' => $cost, 'parts_cost' => 30000])->assertRedirect();

        return $case->estimates()->latest('revision')->firstOrFail();
    }

    private function decision(RepairCase $case, RepairEstimate $estimate, string $decision = 'approved'): void
    {
        $this->op($case, 'decision', ['estimate_id' => $estimate->id, 'decision' => $decision, 'channel' => 'phone', 'evidence' => 'PRIVATE-CONFIRMATION-EVIDENCE', 'staff_recorded_confirmation' => '1'])->assertRedirect();
    }

    private function repairing(User $owner): RepairCase
    {
        $case = $this->inspected($owner);
        $this->decision($case, $this->estimate($case));
        $this->op($case, 'transition', ['status' => 'repairing'])->assertRedirect();

        return $case->fresh();
    }

    private function work(): array
    {
        return ['work_performed' => 'PUBLIC-WORK-PERFORMED', 'parts_replaced' => 'PUBLIC-DESCRIPTIVE-PART', 'internal_notes' => 'SECRET-INTERNAL', 'complete' => '1', 'completion_checks' => 'PRIVATE-COMPLETION-CHECKS', 'final_result' => 'PUBLIC-FINAL-RESULT'];
    }

    private function handover(): array
    {
        return ['recipient_name' => 'PRIVATE-RECIPIENT', 'outcome_notes' => 'PUBLIC-OUTCOME-NOTES', 'delivered_at' => RepairTime::input(), 'handover_confirmed' => '1', 'amounts_confirmed' => '1'];
    }

    public function test_online_requests_never_automatically_create_physical_intake_and_linking_preserves_original(): void
    {
        $this->owner();
        $online = $this->requestRecord();
        $before = $online->fresh()->getAttributes();
        $this->get('/admin/repair-requests/'.$online->id)->assertOk()->assertSee('هنوز پذیرش فیزیکی نیست');
        $this->assertDatabaseCount('repair_cases', 0);
        $this->post('/admin/repairs/intake', $this->intakePayload(['physical_receipt_confirmed' => '0']))->assertSessionHasErrors('physical_receipt_confirmed');
        $this->assertDatabaseCount('repair_cases', 0);
        $case = $this->intake(['repair_request_id' => $online->id, 'status' => 'delivered', 'received_by' => 999]);
        $this->assertSame(RepairStage::Received, $case->status);
        $this->assertSame($online->tracking_reference, $case->tracking_reference);
        $this->assertSame($before, $online->fresh()->getAttributes());
        $this->get('/admin/repair-requests/'.$online->id)->assertSee('پذیرش فیزیکی جداگانه ثبت شده است');
        $this->get('/admin')->assertViewHas('repairCount', 0)->assertViewHas('openRepairCount', 1);
    }

    public function test_duplicate_tokens_linked_requests_and_active_normalized_serials_are_blocked(): void
    {
        $this->owner();
        $online = $this->requestRecord();
        $payload = $this->intakePayload(['repair_request_id' => $online->id]);
        $this->post('/admin/repairs/intake', $payload)->assertRedirect();
        $this->post('/admin/repairs/intake', $payload)->assertConflict();
        $this->post('/admin/repairs/intake', $this->intakePayload(['repair_request_id' => $online->id, 'device_serial' => 'DIFFERENT']))->assertConflict();
        $this->post('/admin/repairs/intake', $this->intakePayload(['device_serial' => ' s e r i a l 0 0 1 ']))->assertConflict();
        $this->assertDatabaseCount('repair_cases', 1);
        $this->assertDatabaseCount('repair_events', 1);
    }

    public function test_successful_workflow_documents_and_delivery_are_accurate_and_auditable(): void
    {
        $owner = $this->owner();
        $case = $this->repairing($owner);
        $this->op($case, 'work', $this->work())->assertRedirect();
        $this->op($case, 'transition', ['status' => 'ready'])->assertRedirect();
        $this->op($case, 'delivery', $this->handover() + ['service_cost' => 1, 'parts_cost' => 1])->assertRedirect();
        $case->refresh();
        $this->assertSame(RepairStage::Delivered, $case->status);
        $this->assertSame(120000, $case->delivery->service_cost);
        $this->assertSame(30000, $case->delivery->parts_cost);
        $this->assertSame($owner->id, $case->delivery->actor_id);
        $this->assertNull($case->active_serial_digest);
        $this->assertNull($case->delivery->confirmation_method);
        $this->get('/admin/repairs/'.$case->public_id.'/documents/intake')->assertOk()->assertSee('PRIVATE-CONDITION')->assertSee('PRIVATE-ACCESSORIES')->assertSee('SERIAL-001')->assertSee('امضا یا تأیید دیجیتال مشتری در این سامانه دریافت نشده');
        $this->get('/admin/repairs/'.$case->public_id.'/documents/summary')->assertOk()->assertSee('PUBLIC-WORK-PERFORMED')->assertSee('PUBLIC-DESCRIPTIVE-PART')->assertDontSee('SECRET-INTERNAL')->assertDontSee('PRIVATE-CONFIRMATION-EVIDENCE');
        $this->get('/admin/repairs/'.$case->public_id.'/documents/delivery')->assertOk()->assertSee('PRIVATE-RECIPIENT')->assertSee('PUBLIC-OUTCOME-NOTES')->assertSee('مدرکی ثبت نشده');
        $this->op($case, 'delivery', $this->handover())->assertConflict();
        $this->op($case, 'assign', ['technician_id' => $owner->id])->assertConflict();
        $this->assertDatabaseCount('repair_deliveries', 1);
        $this->assertDatabaseCount('repair_events', 11);
        $this->assertSame([$owner->id], $case->events()->distinct()->pluck('actor_id')->all());
    }

    public static function invalidTransitions(): array
    {
        return [['received', 'approved'], ['received', 'delivered'], ['received', 'repaired'], ['waiting_inspection', 'ready'], ['inspecting', 'inspected'], ['inspected', 'waiting_approval'], ['waiting_approval', 'approved'], ['approved', 'ready'], ['repairing', 'repaired'], ['repaired', 'delivered'], ['ready', 'delivered'], ['return_pending', 'returned'], ['delivered', 'received'], ['returned', 'waiting_inspection']];
    }

    #[DataProvider('invalidTransitions')]
    public function test_generic_status_updates_cannot_bypass_dedicated_operations(string $from, string $to): void
    {
        $this->owner();
        $case = $this->intake();
        DB::table('repair_cases')->where('id', $case->id)->update(['status' => $from]);
        $this->op($case, 'transition', ['status' => $to])->assertConflict();
        $this->assertSame($from, $case->fresh()->status->value);
        $this->assertDatabaseCount('repair_events', 1);
    }

    public function test_unassigned_and_other_technicians_cannot_inspect_or_work_and_deactivated_sessions_fail(): void
    {
        $this->owner();
        $permissions = ['repairs.view', 'repairs.customer_data', 'repairs.inspect', 'repairs.work'];
        $a = User::factory()->staff($permissions)->create();
        $b = User::factory()->staff($permissions)->create();
        $case = $this->intake();
        $this->op($case, 'assign', ['technician_id' => $a->id])->assertRedirect();
        $this->op($case, 'transition', ['status' => 'waiting_inspection'])->assertRedirect();
        $this->actingAs($b);
        $this->op($case, 'transition', ['status' => 'inspecting'])->assertForbidden();
        $this->op($case, 'inspect', $this->inspection())->assertForbidden();
        $this->op($case, 'assign', ['technician_id' => $b->id])->assertForbidden();
        $this->actingAs($a);
        $this->op($case, 'transition', ['status' => 'inspecting'])->assertRedirect();
        $this->op($case, 'inspect', $this->inspection())->assertRedirect();
        $this->op($case, 'work', $this->work())->assertConflict();
        AdminAccount::query()->where('user_id', $a->id)->update(['is_active' => false, 'session_version' => 2]);
        $this->op($case, 'inspect', $this->inspection())->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->assertDatabaseCount('repair_inspections', 1);
    }

    public function test_assignment_rejects_ordinary_users_and_inactive_or_unqualified_staff(): void
    {
        $this->owner();
        $case = $this->intake();
        foreach ([User::factory()->create(), User::factory()->staff(['repairs.view'])->create(), User::factory()->staff(['repairs.inspect'], false)->create()] as $user) {
            $this->op($case, 'assign', ['technician_id' => $user->id])->assertUnprocessable();
        }
        $this->assertNull($case->fresh()->assigned_to);
    }

    public function test_estimate_revisions_require_fresh_explicit_decisions_and_rejection_blocks_work(): void
    {
        $owner = $this->owner();
        $case = $this->inspected($owner);
        $first = $this->estimate($case);
        $this->decision($case, $first);
        $second = $this->estimate($case, 150000);
        $this->assertSame(2, $second->revision);
        $this->op($case, 'transition', ['status' => 'repairing'])->assertConflict();
        $this->op($case, 'decision', ['estimate_id' => $first->id, 'decision' => 'approved', 'channel' => 'phone', 'evidence' => 'Valid reference', 'staff_recorded_confirmation' => '1'])->assertConflict();
        $this->decision($case, $second, 'rejected');
        $this->assertSame(RepairStage::Inspected, $case->fresh()->status);
        $this->op($case, 'transition', ['status' => 'repairing'])->assertConflict();
        $this->assertSame('approved', $first->fresh()->decisionStatus());
        $this->assertSame('rejected', $second->fresh()->decisionStatus());
        $third = $this->estimate($case, 0);
        $this->decision($case, $third);
        $this->op($case, 'transition', ['status' => 'repairing'])->assertRedirect();
    }

    public function test_decisions_need_evidence_and_confirmation_and_never_flash_sensitive_inputs(): void
    {
        $owner = $this->owner();
        $case = $this->inspected($owner);
        $estimate = $this->estimate($case);
        $this->from('/admin/repairs/'.$case->public_id);
        $this->op($case, 'decision', ['estimate_id' => $estimate->id, 'decision' => 'approved', 'channel' => 'phone', 'evidence' => '', 'staff_recorded_confirmation' => '0'])->assertSessionHasErrors(['evidence', 'staff_recorded_confirmation']);
        $this->assertNull(session('_old_input'));
        $this->assertDatabaseCount('repair_estimate_decisions', 0);
        $this->op($case, 'transition', ['status' => 'repairing'])->assertConflict();
    }

    public function test_new_inspection_pauses_work_and_requires_a_new_estimate_for_the_new_findings(): void
    {
        $owner = $this->owner();
        $case = $this->repairing($owner);
        $this->op($case, 'inspect', $this->inspection())->assertRedirect();
        $this->assertSame(RepairStage::Inspected, $case->fresh()->status);
        $this->op($case, 'work', $this->work())->assertConflict();
        $this->op($case, 'transition', ['status' => 'repairing'])->assertConflict();
        $this->decision($case, $this->estimate($case));
        $this->op($case, 'transition', ['status' => 'repairing'])->assertRedirect();
        $this->op($case, 'work', $this->work())->assertRedirect();
    }

    public function test_delivery_needs_operational_checks_and_return_is_distinct_from_successful_repair(): void
    {
        $this->owner();
        $case = $this->intake();
        $this->op($case, 'delivery', $this->handover())->assertConflict();
        DB::table('repair_cases')->where('id', $case->id)->update(['status' => 'repaired']);
        $this->op($case, 'transition', ['status' => 'ready'])->assertConflict();
        DB::table('repair_cases')->where('id', $case->id)->update(['status' => 'received']);
        $this->op($case, 'transition', ['status' => 'return_pending'])->assertRedirect();
        $this->op($case, 'delivery', array_replace($this->handover(), ['delivered_at' => RepairTime::input(now()->addDay())]))->assertUnprocessable();
        $this->op($case, 'delivery', $this->handover())->assertRedirect();
        $this->assertSame(RepairStage::Returned, $case->fresh()->status);
        $this->assertSame('returned_without_repair', $case->fresh()->delivery->outcome);
        $this->assertNull($case->fresh()->delivery->service_cost);
        $this->intake(); // The same serial may return on a later, explicit intake.
        $this->assertDatabaseCount('repair_cases', 2);
    }

    public function test_stale_versions_cannot_overwrite_a_committed_assignment_or_add_events(): void
    {
        $owner = $this->owner();
        $case = $this->intake();
        $version = $case->version;
        $this->op($case, 'assign', ['technician_id' => $owner->id])->assertRedirect();
        $this->post('/admin/repairs/'.$case->public_id.'/transition', ['version' => $version, 'status' => 'waiting_inspection'])->assertConflict()->assertSee('پرونده تغییر کرده است')->assertDontSee('Stack trace')->assertDontSee('PRIVATE-CUSTOMER');
        $this->assertSame(2, $case->fresh()->version);
        $this->assertSame(RepairStage::Received, $case->fresh()->status);
        $this->assertDatabaseCount('repair_events', 2);
    }

    public function test_audit_failure_rolls_back_intake_and_all_related_records(): void
    {
        $this->owner();
        $payload = $this->intakePayload();
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'repair_events')) {
                throw new \RuntimeException('Synthetic repair audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/repairs/intake', $payload);
            $this->fail('Unaudited intake committed');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic repair audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('repair_cases', 0);
        $this->assertDatabaseCount('repair_events', 0);
        $this->assertDatabaseCount('admin_activities', 0);
    }

    public function test_case_metadata_permissions_hide_all_sensitive_fields_and_block_direct_writes_and_prints(): void
    {
        $this->owner();
        $case = $this->intake();
        $this->actingAs(User::factory()->staff(['repairs.view'])->create());
        foreach (['/admin/repairs', '/admin/repairs/'.$case->public_id] as $uri) {
            $response = $this->get($uri)->assertOk();
            foreach (['PRIVATE-CUSTOMER', '09000000000', 'PRIVATE-MODEL', 'SERIAL-001', 'PRIVATE-ISSUE', 'PRIVATE-CONDITION', 'PRIVATE-ACCESSORIES', $case->tracking_reference] as $secret) {
                $response->assertDontSee($secret);
            }
        }
        foreach (['assign', 'transition', 'inspect', 'estimate', 'decision', 'work', 'delivery'] as $operation) {
            $this->op($case, $operation)->assertForbidden();
        }
        $this->get('/admin/repairs/intake')->assertForbidden();
        $this->get('/admin/repairs/'.$case->public_id.'/documents/intake')->assertForbidden();
        $this->post('/admin/repairs/search', ['q' => '09000000000'])->assertForbidden();
    }

    public function test_encryption_escaping_print_privacy_and_minimal_public_tracking(): void
    {
        $this->owner();
        $case = $this->intake(['customer_name' => '<script>PRIVATE-CUSTOMER</script>']);
        $raw = (array) DB::table('repair_cases')->first();
        foreach (['customer_name', 'contact_phone', 'device_model', 'device_serial', 'issue', 'physical_condition', 'accessories'] as $field) {
            $this->assertNotSame($case->$field, $raw[$field]);
        }
        $this->assertSame(['public_id', 'status'], array_keys($case->toArray()));
        $this->get('/admin/repairs/'.$case->public_id)->assertOk()->assertDontSee('<script>PRIVATE-CUSTOMER</script>', false)->assertSee(e($case->customer_name), false);
        $this->post('/admin/logout');
        $this->post('/repair/track', ['tracking_reference' => $case->tracking_reference, 'contact_phone' => '09000000000'])->assertOk()->assertSee('پروندهٔ خدمات برای این کد ثبت شده است')->assertDontSee('PRIVATE-CUSTOMER')->assertDontSee('SERIAL-001')->assertDontSee($case->tracking_reference);
        $this->post('/repair/track', ['tracking_reference' => $case->tracking_reference, 'contact_phone' => '09000000001'])->assertOk()->assertSee('اطلاعات واردشده قابل تأیید نیست');
        $events = DB::table('repair_events')->get()->toJson().DB::table('admin_activities')->get()->toJson();
        foreach (['PRIVATE-CUSTOMER', '09000000000', 'PRIVATE-MODEL', 'PRIVATE-ISSUE', $case->tracking_reference] as $secret) {
            $this->assertStringNotContainsString($secret, $events);
        }
    }

    public function test_private_search_filters_and_real_metrics_keep_plain_identifiers_out_of_urls_and_sessions(): void
    {
        $this->owner();
        $case = $this->intake();
        $this->post('/admin/repairs/search', ['q' => $case->tracking_reference, 'status' => 'received', 'brand' => 'PAX', 'from' => now()->subDay()->format('Y-m-d'), 'to' => now()->addDay()->format('Y-m-d')])->assertRedirect(route('admin.repair-cases.index'));
        $this->assertStringNotContainsString($case->tracking_reference, json_encode(session('repair_search')));
        $this->get('/admin/repairs')->assertOk()->assertSee($case->public_id)->assertViewHas('counts', fn ($counts) => $counts['all'] === 1 && $counts['open'] === 1 && $counts['approval'] === 0);
        $this->post('/admin/repairs/search', ['q' => '09000000000'])->assertRedirect();
        $this->assertStringNotContainsString('09000000000', json_encode(session('repair_search')));
        $this->post('/admin/repairs/search', ['status' => 'ready'])->assertRedirect();
        $this->get('/admin/repairs')->assertDontSee($case->public_id);
        $this->post('/admin/repairs/search/reset')->assertRedirect();
        $this->assertNull(session('repair_search'));
    }

    public function test_database_errors_hide_sql_bindings_from_logs_and_responses_even_in_debug(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        Route::get('/audit-synthetic-sql-error', fn () => DB::select('select * from nonexistent_repair_table where secret = ?', ['NP-PRIVATE-TRACKING-AND-PHONE']));
        $response = $this->get('/audit-synthetic-sql-error')->assertStatus(500)->assertDontSee('NP-PRIVATE-TRACKING-AND-PHONE')->assertDontSee('nonexistent_repair_table');
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'Database operation failed; SQL and bindings withheld.' && ! str_contains(json_encode($context), 'PRIVATE') && ! isset($context['exception']))->once();
        Log::shouldHaveReceived('error')->once();
    }

    public function test_wrapped_database_errors_are_also_sanitized(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        Route::get('/audit-wrapped-sql-error', function () {
            try {
                DB::select('select * from nonexistent_repair_table where secret = ?', ['PRIVATE-BINDING']);
            } catch (QueryException $exception) {
                throw new \RuntimeException('PRIVATE-WRAPPER', 0, $exception);
            }
        });
        $this->get('/audit-wrapped-sql-error')->assertStatus(500)->assertDontSee('PRIVATE-BINDING')->assertDontSee('PRIVATE-WRAPPER');
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => ! str_contains($message.json_encode($context), 'PRIVATE'))->once();
        Log::shouldHaveReceived('error')->once();
    }

    public function test_guests_ordinary_users_and_csrf_failures_cannot_mutate_repair_operations(): void
    {
        foreach (['/admin/repairs', '/admin/repairs/intake'] as $uri) {
            $this->get($uri)->assertRedirect(route('admin.login'));
        }
        $this->actingAs(User::factory()->create())->get('/admin/repairs')->assertForbidden();
        $this->owner();
        $payload = $this->intakePayload();
        $this->app->detectEnvironment(fn () => 'local');
        $this->post('/admin/repairs/intake', $payload)->assertStatus(419);
        $this->assertDatabaseCount('repair_cases', 0);
    }

    public function test_work_and_state_roll_back_together_if_the_administrative_audit_fails(): void
    {
        $owner = $this->owner();
        $case = $this->repairing($owner);
        $before = $case->fresh()->getAttributes();
        $events = DB::table('repair_events')->count();
        $activities = DB::table('admin_activities')->count();
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'admin_activities')) {
                throw new \RuntimeException('Synthetic final audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->op($case, 'work', $this->work());
            $this->fail('Unaudited work committed');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic final audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $case->fresh()->getAttributes());
        $this->assertDatabaseCount('repair_work_entries', 0);
        $this->assertDatabaseCount('repair_events', $events);
        $this->assertDatabaseCount('admin_activities', $activities);
    }

    public function test_completion_delivery_evidence_and_history_integrity_have_explicit_boundaries(): void
    {
        $owner = $this->owner();
        $case = $this->repairing($owner);
        $this->op($case, 'work', array_replace($this->work(), ['completion_checks' => '']))->assertSessionHasErrors('completion_checks');
        $this->assertDatabaseCount('repair_work_entries', 0);
        $this->op($case, 'work', $this->work())->assertRedirect();
        $entry = $case->workEntries()->firstOrFail();
        try {
            $entry->work_performed = 'Rewritten';
            $entry->save();
            $this->fail('Immutable history rewritten');
        } catch (\LogicException) {
            $this->assertSame('PUBLIC-WORK-PERFORMED', $entry->fresh()->work_performed);
        }
        $this->op($case, 'transition', ['status' => 'ready'])->assertRedirect();
        $this->op($case, 'delivery', $this->handover() + ['confirmation_reference' => 'Reference without method'])->assertSessionHasErrors('confirmation_reference');
        $this->op($case, 'delivery', $this->handover() + ['confirmation_method' => 'document'])->assertSessionHasErrors('confirmation_reference');
        $this->op($case, 'delivery', $this->handover() + ['confirmation_method' => 'document', 'confirmation_reference' => 'Synthetic document reference'])->assertRedirect();
        $this->assertSame('Synthetic document reference', $case->fresh()->delivery->confirmation_reference);
        $this->assertNotSame('Synthetic document reference', DB::table('repair_deliveries')->value('confirmation_reference'));
    }

    public function test_intake_requires_all_permissions_and_estimate_amounts_are_validated_and_normalized(): void
    {
        $owner = $this->owner();
        $case = $this->inspected($owner);
        $this->op($case, 'estimate', ['customer_summary' => 'A valid summary', 'service_cost' => -1, 'parts_cost' => 0])->assertSessionHasErrors('service_cost');
        $this->op($case, 'estimate', ['customer_summary' => 'A valid summary', 'service_cost' => '۱۲۳۰۰۰', 'parts_cost' => '۰'])->assertRedirect();
        $this->assertSame(123000, $case->estimates()->firstOrFail()->service_cost);
        $this->actingAs(User::factory()->staff(['repairs.view', 'repairs.intake'])->create());
        $this->post('/admin/repairs/intake', $this->intakePayload())->assertForbidden();
    }

    public function test_historical_technicians_remain_filterable_after_deactivation(): void
    {
        $this->owner();
        $case = $this->intake();
        $tech = User::factory()->staff(['repairs.view', 'repairs.customer_data', 'repairs.inspect'])->create();
        $this->op($case, 'assign', ['technician_id' => $tech->id])->assertRedirect();
        AdminAccount::query()->where('user_id', $tech->id)->update(['is_active' => false]);
        $this->get('/admin/repairs')->assertOk()->assertViewHas('technicians', fn ($users) => $users->contains('id', $tech->id));
        $this->post('/admin/repairs/search', ['technician' => $tech->id])->assertRedirect();
        $this->get('/admin/repairs')->assertSee($case->public_id);
        $this->op($case, 'assign', ['technician_id' => $tech->id])->assertUnprocessable();
    }
}
