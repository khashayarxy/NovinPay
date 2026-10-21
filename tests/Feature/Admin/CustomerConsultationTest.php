<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAccount;
use App\Models\AdminActivity;
use App\Models\Consultation;
use App\Models\ConsultationEntry;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\User;
use App\Support\RepairIdentity;
use App\Support\RepairTime;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerConsultationTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        return $user;
    }

    private function profile(array $extra = []): Customer
    {
        $data = array_replace(['name' => 'PRIVATE-PROFILE', 'contact_phone' => '09000000000', 'is_active' => '1', 'identity_reviewed' => '1', 'reason' => 'PRIVATE-REVIEW-REASON'], $extra);
        $this->post('/admin/customers', $data)->assertRedirect();

        return Customer::query()->latest('id')->firstOrFail();
    }

    private function consultation(array $extra = []): Consultation
    {
        $token = bin2hex(random_bytes(32));
        $this->withSession(['consultation_token' => $token])->post('/consultation', array_replace(['submission_token' => $token, 'customer_name' => 'PRIVATE-CONSULT-NAME', 'contact_phone' => '09000000000', 'topic' => 'purchase', 'device_info' => 'PRIVATE-DEVICE', 'question' => 'PRIVATE-CONSULT-QUESTION'], $extra))->assertRedirect('/consultation/confirmation');

        return Consultation::query()->latest('id')->firstOrFail();
    }

    private function requestRecord(): RepairRequest
    {
        $record = new RepairRequest;
        foreach (['customer_name' => 'HISTORICAL-NAME', 'contact_phone' => '09000000000', 'device_brand' => 'PAX', 'device_model' => 'HISTORICAL-MODEL', 'issue' => 'HISTORICAL-ISSUE'] as $key => $value) {
            $record->$key = $value;
        }
        $record->tracking_reference = 'NP-'.strtoupper(bin2hex(random_bytes(16)));
        $record->submission_digest = bin2hex(random_bytes(32));
        $record->phone_digest = RepairIdentity::digest('phone', $record->contact_phone);
        $record->status = 'online_submitted';
        $record->save();

        return $record;
    }

    private function link(Customer $customer, string $type, string $record, array $extra = [])
    {
        return $this->post('/admin/customers/'.$customer->public_id.'/link', array_replace(['version' => $customer->fresh()->version, 'type' => $type, 'record' => $record, 'unlink' => '0', 'identity_reviewed' => '1', 'reason' => 'PRIVATE-LINK-REASON'], $extra));
    }

    private function operation(Consultation $consultation, string $operation, array $extra = [])
    {
        return $this->post('/admin/consultations/'.$consultation->public_id.'/'.$operation, ['version' => $consultation->fresh()->version] + $extra);
    }

    public function test_profile_normalization_encryption_and_safe_serialization(): void
    {
        $this->owner();
        $customer = $this->profile(['contact_phone' => '+۹۸ ۹۰۰-۰۰۰-۰۰۰۰', 'name' => '<script>PRIVATE-PROFILE</script>']);
        $this->assertSame('09000000000', $customer->contact_phone);
        $row = DB::table('customers')->find($customer->id);
        $this->assertStringNotContainsString('PRIVATE-PROFILE', $row->name);
        $this->assertStringNotContainsString('09000000000', $row->contact_phone);
        $this->assertSame(['public_id', 'is_active'], array_keys($customer->toArray()));
        $this->get('/admin/customers/'.$customer->public_id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>PRIVATE', false);
    }

    public function test_duplicate_detection_never_automatically_merges_unverified_phones(): void
    {
        $this->owner();
        $first = $this->profile();
        $base = ['name' => 'Different person', 'contact_phone' => '09000000000', 'identity_reviewed' => '1', 'is_active' => '1', 'reason' => 'Reviewed shared family phone'];
        $this->post('/admin/customers', $base)->assertStatus(409);
        $this->post('/admin/customers', $base + ['duplicate_reviewed' => '1'])->assertRedirect();
        $this->assertDatabaseCount('customers', 2);
        $this->assertNull($first->fresh()->merged_into_id);
        $this->post('/admin/customers', array_replace($base, ['name' => 'PRIVATE-PROFILE', 'duplicate_reviewed' => '1']))->assertStatus(409);
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('customer_identity_keys', 1);
    }

    public function test_stale_profile_update_cannot_overwrite_or_change_repair_history(): void
    {
        $this->owner();
        $customer = $this->profile();
        $data = ['version' => 1, 'name' => 'Changed profile', 'contact_phone' => '09000000000', 'is_active' => '1', 'reason' => 'Verified correction', 'identity_reviewed' => '1'];
        $this->put('/admin/customers/'.$customer->public_id, $data)->assertRedirect();
        $this->put('/admin/customers/'.$customer->public_id, array_replace($data, ['name' => 'Stale overwrite']))->assertStatus(409);
        $this->assertSame('Changed profile', $customer->fresh()->name);
        $this->assertDatabaseCount('customer_notes', 2);
    }

    public function test_explicit_repair_request_link_preserves_snapshot_and_tracking(): void
    {
        $this->owner();
        $customer = $this->profile();
        $record = $this->requestRecord();
        $snapshot = DB::table('repair_requests')->find($record->id);
        $this->assertNull($record->customer_id);
        $this->link($customer, 'request', (string) $record->id)->assertRedirect();
        $after = DB::table('repair_requests')->find($record->id);
        foreach (['customer_name', 'contact_phone', 'device_model', 'issue', 'status', 'tracking_reference', 'phone_digest', 'submission_digest'] as $field) {
            $this->assertSame($snapshot->$field, $after->$field);
        }
        $this->assertSame($customer->id, (int) $after->customer_id);
        $this->get('/admin/repair-requests/'.$record->id)->assertOk()->assertSee($customer->public_id);
        $this->get('/admin/customers/'.$customer->public_id)->assertOk()->assertSee('درخواست آنلاین');
        $this->post('/repair/track', ['tracking_reference' => $record->tracking_reference, 'contact_phone' => '09000000000'])->assertOk()->assertDontSee('PRIVATE-PROFILE');
        $this->link($customer, 'request', (string) $record->id, ['unlink' => '1'])->assertRedirect();
        $this->assertNull($record->fresh()->customer_id);
    }

    public function test_physical_case_link_cannot_change_status_or_snapshot_or_implicitly_link_online_request(): void
    {
        $this->owner();
        $customer = $this->profile();
        $request = $this->requestRecord();
        $token = bin2hex(random_bytes(32));
        $this->withSession(['repair_intake_token' => $token])->post('/admin/repairs/intake', ['submission_token' => $token, 'repair_request_id' => $request->id, 'customer_name' => 'HISTORICAL-INTAKE', 'contact_phone' => '09000000000', 'device_brand' => 'PAX', 'device_model' => 'PAX MODEL', 'device_serial' => 'DEMO-001', 'issue' => 'Reported fault', 'physical_condition' => 'Intact device', 'accessories' => 'No accessories', 'received_at' => RepairTime::input(now()->subHour()), 'physical_receipt_confirmed' => '1'])->assertRedirect();
        $case = RepairCase::query()->firstOrFail();
        $before = DB::table('repair_cases')->find($case->id);
        $this->link($customer, 'case', $case->public_id, ['status' => 'delivered', 'customer_name' => 'Injected'])->assertRedirect();
        $after = DB::table('repair_cases')->find($case->id);
        foreach (['customer_name', 'contact_phone', 'status', 'tracking_reference', 'device_serial', 'received_at'] as $field) {
            $this->assertSame($before->$field, $after->$field);
        }
        $this->assertNull($request->fresh()->customer_id);
        $this->assertDatabaseCount('repair_events', 1);
        $this->get('/admin/repairs/'.$case->public_id)->assertOk()->assertSee($customer->public_id);
    }

    public function test_link_requires_source_permissions_and_review_and_prevents_conflicting_relink(): void
    {
        $owner = $this->owner();
        $customer = $this->profile();
        $other = $this->profile(['contact_phone' => '09000000001']);
        $request = $this->requestRecord();
        $staff = User::factory()->staff(['customers.view', 'customers.contact', 'customers.link'])->create();
        $this->actingAs($staff);
        $this->link($customer, 'request', (string) $request->id)->assertForbidden();
        $this->actingAs($owner);
        $this->link($customer, 'request', (string) $request->id, ['identity_reviewed' => '0'])->assertSessionHasErrors('identity_reviewed');
        $this->link($customer, 'request', (string) $request->id)->assertRedirect();
        $this->link($other, 'request', (string) $request->id)->assertStatus(409);
        $this->assertSame($customer->id, (int) $request->fresh()->customer_id);
    }

    public function test_reconciliation_moves_only_links_and_retains_both_snapshots_and_private_notes(): void
    {
        $this->owner();
        $source = $this->profile();
        $destination = $this->profile(['name' => 'Reviewed alternate spelling', 'duplicate_reviewed' => '1']);
        $consultation = $this->consultation();
        $this->link($source, 'consultation', $consultation->public_id)->assertRedirect();
        $snapshot = DB::table('consultations')->find($consultation->id);
        $this->post('/admin/customers/'.$source->public_id.'/reconcile', ['version' => $source->fresh()->version, 'destination' => $destination->public_id, 'destination_version' => $destination->version, 'identity_reviewed' => '1', 'reason' => 'PRIVATE-RECONCILIATION-EVIDENCE'])->assertRedirect();
        $this->assertSame($destination->id, (int) $consultation->fresh()->customer_id);
        $this->assertSame($snapshot->customer_name, DB::table('consultations')->find($consultation->id)->customer_name);
        $this->assertSame('PRIVATE-PROFILE', $source->fresh()->name);
        $this->assertSame('Reviewed alternate spelling', $destination->fresh()->name);
        $this->assertFalse($source->fresh()->is_active);
        $this->assertSame($destination->id, (int) $source->fresh()->merged_into_id);
        $this->assertSame(2, Customer::query()->count());
        $this->get('/admin/customers/'.$source->public_id.'/edit')->assertStatus(409);
        $this->get('/admin/customers/'.$destination->public_id)->assertOk()->assertSee($source->public_id);
    }

    public function test_reconciliation_requires_each_source_permission_and_is_atomic(): void
    {
        $this->owner();
        $source = $this->profile();
        $destination = $this->profile(['contact_phone' => '09000000001']);
        $record = $this->requestRecord();
        $this->link($source, 'request', (string) $record->id)->assertRedirect();
        $staff = User::factory()->staff(['customers.view', 'customers.contact', 'customers.manage', 'customers.link', 'customers.reconcile', 'customers.notes'])->create();
        $this->actingAs($staff)->post('/admin/customers/'.$source->public_id.'/reconcile', ['version' => $source->fresh()->version, 'destination' => $destination->public_id, 'destination_version' => $destination->version, 'identity_reviewed' => '1', 'reason' => 'Review evidence'])->assertForbidden();
        $this->assertSame($source->id, (int) $record->fresh()->customer_id);
        $this->assertTrue($source->fresh()->is_active);
    }

    public function test_archival_preserves_links_and_can_be_reactivated_but_not_used_for_new_links(): void
    {
        $this->owner();
        $customer = $this->profile(['is_active' => '0']);
        $this->link($customer, 'request', (string) $this->requestRecord()->id)->assertStatus(409);
        $this->put('/admin/customers/'.$customer->public_id, ['version' => 1, 'name' => $customer->name, 'contact_phone' => '09000000000', 'is_active' => '1', 'identity_reviewed' => '1', 'reason' => 'Verified reactivation'])->assertRedirect();
        $this->assertTrue($customer->fresh()->is_active);
    }

    public function test_private_notes_require_separate_permission_and_are_append_only(): void
    {
        $owner = $this->owner();
        $customer = $this->profile();
        $this->post('/admin/customers/'.$customer->public_id.'/note', ['version' => 1, 'body' => 'SECRET-INTERNAL-NOTE'])->assertRedirect();
        $staff = User::factory()->staff(['customers.view', 'customers.contact', 'customers.manage'])->create();
        $this->actingAs($staff)->get('/admin/customers/'.$customer->public_id)->assertOk()->assertDontSee('SECRET-INTERNAL-NOTE')->assertDontSee('PRIVATE-REVIEW-REASON');
        $this->post('/admin/customers/'.$customer->public_id.'/note', ['version' => 2, 'body' => 'Unauthorized note'])->assertForbidden();
        $this->actingAs($owner)->get('/admin/customers/'.$customer->public_id)->assertOk()->assertSee('SECRET-INTERNAL-NOTE');
        $this->assertStringNotContainsString('SECRET-INTERNAL-NOTE', DB::table('customer_notes')->latest('id')->value('body'));
        $this->expectException(\LogicException::class);
        $note = CustomerNote::query()->latest('id')->first();
        $note->body = 'Tampered';
        $note->save();
    }

    public function test_public_submission_is_private_normalized_and_never_creates_or_links_profile(): void
    {
        $this->owner();
        $customer = $this->profile();
        $record = $this->consultation(['contact_phone' => '0098۹۰۰۰۰۰۰۰۰۰']);
        $this->assertSame('09000000000', $record->contact_phone);
        $this->assertNull($record->customer_id);
        $this->assertDatabaseCount('customers', 1);
        foreach (['customer_name', 'contact_phone', 'device_info', 'question'] as $field) {
            $this->assertNotSame($record->$field, DB::table('consultations')->find($record->id)->$field);
        }
        $this->get('/consultation/confirmation')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($record->customer_name)->assertDontSee($record->contact_phone)->assertDontSee($record->public_id);
        $this->assertArrayNotHasKey('question', $record->toArray());
        $this->assertSame('PRIVATE-PROFILE', $customer->fresh()->name);
    }

    public function test_duplicate_public_token_and_invalid_input_never_flash_personal_data(): void
    {
        $token = str_repeat('a', 64);
        $payload = ['submission_token' => $token, 'customer_name' => 'PRIVATE-FORM-NAME', 'contact_phone' => '09000000000', 'topic' => 'repair', 'question' => 'PRIVATE-FORM-QUESTION'];
        $this->withSession(['consultation_token' => $token])->post('/consultation', $payload)->assertRedirect();
        $this->withSession(['consultation_token' => $token])->post('/consultation', $payload)->assertSessionHasErrors('submission_token')->assertSessionMissing('consultation_token');
        $this->assertDatabaseCount('consultations', 1);
        $this->withSession(['consultation_token' => $token])->post('/consultation', array_replace($payload, ['contact_phone' => 'invalid']))->assertSessionHasErrors('contact_phone')->assertSessionMissing('_old_input');
        $this->get('/consultation')->assertOk()->assertDontSee('PRIVATE-FORM-NAME')->assertDontSee('PRIVATE-FORM-QUESTION');
    }

    public function test_consultation_phone_throttle_and_validation(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->consultation();
        }
        $token = bin2hex(random_bytes(32));
        $this->withSession(['consultation_token' => $token])->post('/consultation', ['submission_token' => $token, 'customer_name' => 'Another person', 'contact_phone' => '09000000000', 'topic' => 'repair', 'question' => 'Another valid question'])->assertStatus(429)->assertDontSee('09000000000');
        $this->assertDatabaseCount('consultations', 3);
    }

    public function test_controlled_consultation_workflow_requires_real_contact_and_closed_records_are_immutable(): void
    {
        $owner = $this->owner();
        $record = $this->consultation();
        $this->operation($record, 'transition', ['status' => 'contacted'])->assertStatus(409);
        $this->operation($record, 'transition', ['status' => 'in_progress'])->assertStatus(409);
        $this->operation($record, 'assign', ['assigned_to' => $owner->id])->assertRedirect();
        $this->operation($record, 'transition', ['status' => 'in_progress'])->assertRedirect();
        $this->operation($record, 'transition', ['status' => 'contacted'])->assertStatus(409);
        $this->operation($record, 'entry', ['kind' => 'contact', 'channel' => 'phone', 'outcome' => 'no_answer', 'body' => 'PRIVATE-CONTACT-NO-ANSWER'])->assertRedirect();
        $this->operation($record, 'transition', ['status' => 'contacted'])->assertStatus(409);
        $this->operation($record, 'entry', ['kind' => 'contact', 'channel' => 'in_person', 'outcome' => 'answered', 'body' => 'PRIVATE-CONTACT-EVIDENCE'])->assertRedirect();
        $this->operation($record, 'transition', ['status' => 'contacted'])->assertRedirect();
        $this->operation($record, 'transition', ['status' => 'closed'])->assertRedirect();
        $this->operation($record, 'entry', ['kind' => 'note', 'body' => 'Closed record mutation'])->assertStatus(409);
        $this->operation($record, 'transition', ['status' => 'in_progress'])->assertStatus(409);
        $this->assertSame('closed', $record->fresh()->status);
        $this->get('/admin/consultations/'.$record->public_id)->assertOk()->assertSee('PRIVATE-CONTACT-EVIDENCE');
    }

    public function test_inactive_unqualified_staff_and_stale_consultation_updates_are_rejected(): void
    {
        $owner = $this->owner();
        $record = $this->consultation();
        foreach ([User::factory()->create(), User::factory()->staff(['consultations.view', 'consultations.manage'], false)->create(), User::factory()->staff(['consultations.view'])->create()] as $staff) {
            $this->operation($record, 'assign', ['assigned_to' => $staff->id])->assertStatus(422);
        }
        $this->operation($record, 'assign', ['assigned_to' => $owner->id])->assertRedirect();
        $this->post('/admin/consultations/'.$record->public_id.'/transition', ['version' => 1, 'status' => 'closed'])->assertStatus(409);
        $this->assertSame('new', $record->fresh()->status);
    }

    public function test_metadata_permissions_do_not_expose_contact_notes_or_linked_history(): void
    {
        $this->owner();
        $customer = $this->profile();
        $record = $this->consultation();
        $this->link($customer, 'consultation', $record->public_id)->assertRedirect();
        $staff = User::factory()->staff(['customers.view', 'consultations.view'])->create();
        $this->actingAs($staff);
        foreach (['/admin/customers', '/admin/customers/'.$customer->public_id, '/admin/consultations', '/admin/consultations/'.$record->public_id] as $url) {
            $response = $this->get($url)->assertOk();
            foreach (['PRIVATE-PROFILE', 'PRIVATE-REVIEW-REASON', 'PRIVATE-CONSULT-NAME', 'PRIVATE-CONSULT-QUESTION', '09000000000'] as $secret) {
                $response->assertDontSee($secret);
            }
        }
        $this->get('/admin/customers/create')->assertForbidden();
        $this->get('/admin/customers/'.$customer->public_id)->assertDontSee('عملیات پروفایل ↓');
        $this->post('/admin/customers/search', ['q' => '09000000000'])->assertForbidden();
        $this->post('/admin/consultations/search', ['q' => '09000000000'])->assertForbidden();
    }

    public static function protectedOperations(): array
    {
        return array_map(fn ($path) => [$path], ['customers', 'customers/CUSTOMER', 'customers/CUSTOMER/link', 'customers/CUSTOMER/reconcile', 'customers/CUSTOMER/note', 'consultations/CONSULTATION/assign', 'consultations/CONSULTATION/transition', 'consultations/CONSULTATION/entry']);
    }

    #[DataProvider('protectedOperations')]
    public function test_unauthorized_direct_http_operations_are_default_denied(string $path): void
    {
        $this->owner();
        $customer = $this->profile();
        $record = $this->consultation();
        $staff = User::factory()->staff(['dashboard.view'])->create();
        $path = str_replace(['CUSTOMER', 'CONSULTATION'], [$customer->public_id, $record->public_id], $path);
        $this->actingAs($staff);
        $method = $path === 'customers/'.$customer->public_id ? 'put' : 'post';
        $this->$method('/admin/'.$path, ['version' => 1, 'is_active' => 1])->assertForbidden();
        $this->assertSame(1, $customer->fresh()->version);
        $this->assertSame(1, $record->fresh()->version);
    }

    public function test_private_search_uses_only_hmacs_and_filter_links_never_contain_personal_values(): void
    {
        $this->owner();
        $customer = $this->profile();
        $record = $this->consultation();
        $this->post('/admin/customers/search', ['q' => 'PRIVATE-PROFILE'])->assertRedirect()->assertSessionHas('customer_search.column', 'name_digest');
        $this->assertStringNotContainsString('PRIVATE-PROFILE', json_encode(session('customer_search')));
        $this->get('/admin/customers')->assertOk()->assertSee($customer->public_id);
        $this->post('/admin/consultations/search', ['q' => '+989000000000', 'status' => 'new'])->assertRedirect();
        $this->assertStringNotContainsString('09000000000', json_encode(session('consultation_search')));
        $this->get('/admin/consultations')->assertOk()->assertSee($record->customer_name)->assertDontSee('?q=');
        $this->post('/admin/customers/search', ['q' => str_repeat('X', 130)])->assertSessionHasErrors('q')->assertSessionMissing('_old_input');
    }

    public function test_all_new_screens_render_and_old_public_contact_remains_available(): void
    {
        $this->owner();
        $customer = $this->profile();
        $record = $this->consultation();
        foreach (['/admin/customers', '/admin/customers/create', '/admin/customers/'.$customer->public_id, '/admin/customers/'.$customer->public_id.'/edit', '/consultation', '/contact', '/admin/consultations', '/admin/consultations/'.$record->public_id] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_permission_revocation_and_deactivation_invalidate_crm_sessions(): void
    {
        $this->owner();
        $customer = $this->profile();
        $staff = User::factory()->staff(['customers.view', 'customers.contact', 'customers.manage'])->create();
        $this->actingAs($staff)->get('/admin/customers')->assertOk();
        $account = AdminAccount::query()->where('user_id', $staff->id)->first();
        $account->permissions = ['customers.view'];
        $account->session_version++;
        $account->save();
        $this->get('/admin/customers/'.$customer->public_id)->assertRedirect('/admin/login');
        $this->actingAs($staff);
        $account->is_active = false;
        $account->save();
        $this->get('/admin/customers')->assertRedirect('/admin/login');
    }

    public function test_customer_audit_failure_rolls_back_entire_transaction_and_redacts_errors(): void
    {
        $this->owner();
        Log::spy();
        AdminActivity::creating(fn () => throw new QueryException('testing', 'INSERT SECRET SQL ?', ['PRIVATE-REVIEW-REASON'], new \PDOException('private failure')));
        try {
            $this->post('/admin/customers', ['name' => 'PRIVATE-PROFILE', 'contact_phone' => '09000000000', 'is_active' => '1', 'identity_reviewed' => '1', 'reason' => 'PRIVATE-REVIEW-REASON'])->assertStatus(500)->assertDontSee('PRIVATE-REVIEW-REASON')->assertDontSee('SECRET SQL');
            $this->assertDatabaseCount('customers', 0);
            $this->assertDatabaseCount('customer_notes', 0);
            $this->assertDatabaseCount('customer_identity_keys', 0);
            Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'Database operation failed; SQL and bindings withheld.' && array_keys($context) === ['sqlstate']);
        } finally {
            AdminActivity::flushEventListeners();
        }
    }

    public function test_consultation_entry_validation_and_append_only_integrity(): void
    {
        $this->owner();
        $record = $this->consultation();
        $this->operation($record, 'entry', ['kind' => 'contact', 'body' => 'Private contact'])->assertSessionHasErrors(['channel', 'outcome']);
        $this->operation($record, 'entry', ['kind' => 'note', 'body' => 'Private note', 'channel' => 'phone'])->assertSessionHasErrors('channel');
        $this->operation($record, 'entry', ['kind' => 'note', 'body' => 'SECRET-CONSULT-NOTE'])->assertRedirect();
        $this->assertStringNotContainsString('SECRET-CONSULT-NOTE', DB::table('consultation_entries')->value('body'));
        $this->expectException(\LogicException::class);
        ConsultationEntry::query()->first()->delete();
    }

    public function test_contact_permission_does_not_grant_internal_history_or_management(): void
    {
        $this->owner();
        $record = $this->consultation();
        $this->operation($record, 'entry', ['kind' => 'note', 'body' => 'SECRET-CONSULT-NOTE'])->assertRedirect();
        $staff = User::factory()->staff(['consultations.view', 'consultations.contact'])->create();
        $this->actingAs($staff)->get('/admin/consultations/'.$record->public_id)->assertOk()->assertSee('PRIVATE-CONSULT-QUESTION')->assertDontSee('SECRET-CONSULT-NOTE');
        $this->operation($record, 'transition', ['status' => 'closed'])->assertForbidden();
        $this->operation($record, 'entry', ['kind' => 'note', 'body' => 'Forbidden note'])->assertForbidden();
    }

    public function test_explicitly_granted_staff_can_create_profiles_without_repair_or_consultation_access(): void
    {
        $staff = User::factory()->staff(['customers.view', 'customers.contact', 'customers.manage'])->create();
        $this->actingAs($staff);
        $customer = $this->profile();
        $this->get('/admin/customers/'.$customer->public_id)->assertOk()->assertSee('PRIVATE-PROFILE')->assertDontSee('PRIVATE-REVIEW-REASON');
        $this->get('/admin/repair-requests')->assertForbidden();
        $this->get('/admin/consultations')->assertForbidden();
        $this->get('/admin/staff')->assertForbidden();
    }

    public function test_staff_provisioning_rejects_missing_crm_permission_dependencies(): void
    {
        $owner = $this->owner();
        $owner->password = 'Owner-Test-6B-2026';
        $owner->save();
        foreach ([['customers.manage'], ['customers.view', 'customers.reconcile'], ['consultations.history', 'consultations.view']] as $permissions) {
            $this->post('/admin/staff', ['name' => 'Synthetic colleague', 'email' => 'new-staff@example.test', 'password' => 'Staff-Test-6B-2026', 'password_confirmation' => 'Staff-Test-6B-2026', 'current_password' => 'Owner-Test-6B-2026', 'is_active' => '1', 'permissions' => $permissions])->assertSessionHasErrors('permissions');
        }
        $this->assertDatabaseCount('admin_accounts', 1);
    }

    public function test_editing_profile_never_overwrites_linked_historical_customer_fields(): void
    {
        $this->owner();
        $customer = $this->profile();
        $request = $this->requestRecord();
        $consultation = $this->consultation();
        $this->link($customer, 'request', (string) $request->id)->assertRedirect();
        $this->link($customer, 'consultation', $consultation->public_id)->assertRedirect();
        $this->put('/admin/customers/'.$customer->public_id, ['version' => $customer->fresh()->version, 'name' => 'Corrected current profile', 'contact_phone' => '09000000001', 'is_active' => '1', 'identity_reviewed' => '1', 'reason' => 'Synthetic independently verified correction'])->assertRedirect();
        $this->assertSame('HISTORICAL-NAME', $request->fresh()->customer_name);
        $this->assertSame('PRIVATE-CONSULT-NAME', $consultation->fresh()->customer_name);
        $this->assertSame('09000000000', $consultation->fresh()->contact_phone);
        $this->assertSame('new', $consultation->fresh()->status);
    }

    public function test_reconciliation_rejects_self_and_stale_destination(): void
    {
        $this->owner();
        $source = $this->profile();
        $destination = $this->profile(['contact_phone' => '09000000001']);
        $base = ['version' => 1, 'destination_version' => 1, 'identity_reviewed' => '1', 'reason' => 'Synthetic review'];
        $this->post('/admin/customers/'.$source->public_id.'/reconcile', $base + ['destination' => $source->public_id])->assertStatus(422);
        $this->post('/admin/customers/'.$destination->public_id.'/note', ['version' => 1, 'body' => 'Changed destination'])->assertRedirect();
        $this->post('/admin/customers/'.$source->public_id.'/reconcile', $base + ['destination' => $destination->public_id])->assertStatus(409);
        $this->assertNull($source->fresh()->merged_into_id);
    }

    public function test_invalid_record_identifier_cannot_be_coerced_into_repair_request_id(): void
    {
        $this->owner();
        $customer = $this->profile();
        $request = $this->requestRecord();
        $this->link($customer, 'request', $request->id.'-wrong')->assertSessionHasErrors(['record' => 'شمارهٔ درخواست آنلاین معتبر وارد کنید.']);
        $this->link($customer, 'case', 'not-a-uuid')->assertSessionHasErrors(['record' => 'شناسهٔ کامل پرونده یا مشاوره معتبر وارد کنید.']);
        $this->assertNull($request->fresh()->customer_id);
    }

    public function test_public_consultation_errors_use_persian_field_names_without_replaying_private_input(): void
    {
        $this->post('/consultation', ['submission_token' => str_repeat('a', 64), 'customer_name' => 'PRIVATE-INVALID-NAME', 'contact_phone' => 'invalid', 'topic' => 'purchase', 'question' => 'PRIVATE-INVALID-QUESTION'])
            ->assertSessionHasErrors(['contact_phone' => 'قالب شمارهٔ همراه نامعتبر است.'])
            ->assertSessionMissing('_old_input');
        $this->get('/consultation')->assertOk()->assertSee('شمارهٔ همراه')->assertDontSee('contact phone')->assertDontSee('PRIVATE-INVALID-NAME')->assertDontSee('PRIVATE-INVALID-QUESTION');
        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_consultation_audit_failure_rolls_back_entry_version_and_history(): void
    {
        $this->owner();
        $record = $this->consultation();
        AdminActivity::creating(fn () => throw new QueryException('testing', 'PRIVATE SQL ?', ['PRIVATE-NOTE'], new \PDOException('private failure')));
        try {
            $this->operation($record, 'entry', ['kind' => 'note', 'body' => 'PRIVATE-NOTE'])->assertStatus(500)->assertDontSee('PRIVATE-NOTE');
            $this->assertSame(1, $record->fresh()->version);
            $this->assertDatabaseCount('consultation_entries', 0);
        } finally {
            AdminActivity::flushEventListeners();
        }
    }

    public function test_public_consultation_validation_bounds_and_confirmation_cannot_be_enumerated(): void
    {
        $this->get('/consultation/confirmation')->assertRedirect('/consultation');
        $this->post('/consultation', ['submission_token' => str_repeat('a', 64), 'customer_name' => 'PRIVATE-INVALID-NAME', 'contact_phone' => '09000000000', 'topic' => 'arbitrary-topic', 'device_info' => str_repeat('a', 201), 'question' => str_repeat('b', 2001)])->assertSessionHasErrors(['topic', 'device_info', 'question'])->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('consultations', 0);
        $this->get('/consultation/1')->assertNotFound();
    }

    public function test_public_consultation_ip_limit_applies_across_different_phone_numbers(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->consultation(['contact_phone' => '090000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)]);
        }
        $this->post('/consultation', [])->assertStatus(429);
        $this->assertDatabaseCount('consultations', 10);
    }
}
