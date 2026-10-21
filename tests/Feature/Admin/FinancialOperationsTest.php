<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAccount;
use App\Models\AdminActivity;
use App\Models\Customer;
use App\Models\FinancialDocument;
use App\Models\FinancialEntry;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\CustomerManagement;
use App\Services\FinancialOperations;
use App\Services\FinancialReporting;
use App\Services\RepairOperations;
use App\Support\FinanceMoney;
use App\Support\RepairTime;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinancialOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->superAdmin()->create();
        $this->actingAs($this->actor);
        $this->customer = $this->customer();
    }

    private function customer(): Customer
    {
        $customer = new Customer;
        foreach (['public_id' => (string) Str::uuid(), 'name' => 'PRIVATE-FINANCE-CUSTOMER', 'contact_phone' => '09000000012', 'name_digest' => bin2hex(random_bytes(32)), 'phone_digest' => bin2hex(random_bytes(32)), 'identity_digest' => bin2hex(random_bytes(32)), 'is_active' => true] as $key => $value) {
            $customer->$key = $value;
        }
        $customer->save();

        return $customer->fresh();
    }

    private function order(string $total = '100.10', string $status = 'fulfilled'): SalesOrder
    {
        $order = new SalesOrder;
        foreach (['public_id' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'status' => $status, 'total' => $total, 'currency' => 'TOMAN', 'created_by' => $this->actor->id, 'version' => 4] as $key => $value) {
            $order->$key = $value;
        }
        $order->save();

        return $order;
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['operation_key' => (string) Str::uuid(), 'password' => 'password', 'reason' => 'PRIVATE-FINANCIAL-EVIDENCE'], $extra);
    }

    private function command(string $action, array $extra = []): FinancialEntry
    {
        return FinancialEntry::findOrFail(app(FinancialOperations::class)->execute($this->actor, $action, $this->payload($extra)));
    }

    private function issue(?SalesOrder $order = null): FinancialDocument
    {
        $order ??= $this->order();
        $entry = $this->command('issue', ['source_type' => 'order', 'source' => $order->public_id, 'source_version' => $order->version]);

        return FinancialDocument::findOrFail($entry->financial_document_id);
    }

    private function receipt(FinancialDocument $document, string $amount = '20.01', array $extra = []): FinancialEntry
    {
        return $this->command('receipt', array_replace(['document' => $document->public_id, 'version' => $document->fresh()->version, 'amount' => $amount, 'reference' => 'PAY-'.Str::upper(Str::random(12)), 'method' => 'transfer'], $extra));
    }

    private function reverse(FinancialEntry $entry): FinancialEntry
    {
        return $this->command('reverse', ['entry' => $entry->public_id, 'version' => $entry->financial_document_id ? FinancialDocument::findOrFail($entry->financial_document_id)->version : null]);
    }

    private function conflict(callable $callback, int $status = 409): void
    {
        try {
            $callback();
            $this->fail('Expected denial');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    public function test_exact_decimal_and_large_amounts_never_use_floating_arithmetic(): void
    {
        $this->assertSame(10, FinanceMoney::minor('0.10'));
        $this->assertSame(123, FinanceMoney::minor('۱٫۲۳'));
        $this->assertSame(999999999999999, FinanceMoney::minor('9999999999999.99'));
        $this->assertSame('9,999,999,999,999.99', FinanceMoney::display(999999999999999));
        $this->assertSame('−0.01', FinanceMoney::display(-1));
        $this->conflict(fn () => FinanceMoney::minor('1.001'), 422);
        $this->conflict(fn () => FinanceMoney::minor('1e3'), 422);
        $document = $this->issue();
        $this->receipt($document, '20.01');
        $this->receipt($document, '30.09');
        $this->assertSame(5010, $document->fresh()->paid_minor);
        $this->assertSame('partial', $document->fresh()->settlement());
        $this->receipt($document, '50.00');
        $this->assertSame('settled', $document->fresh()->settlement());
        $this->assertSame(0, app(FinancialOperations::class)->reconcile($document->fresh())['outstanding']);
    }

    public function test_business_state_alone_never_creates_financial_records(): void
    {
        $this->order();
        $this->order('300.00', 'confirmed');
        $this->assertSame(0, FinancialDocument::count());
        $this->assertSame(0, FinancialEntry::count());
        $this->conflict(fn () => $this->issue($this->order('100.00', 'confirmed')));
        $this->conflict(fn () => $this->issue($this->order('100.00', 'cancelled')));
        $this->conflict(fn () => $this->issue($this->order('0.00')), 422);
    }

    public function test_duplicate_document_and_stale_source_are_denied(): void
    {
        $order = $this->order();
        $this->conflict(fn () => $this->command('issue', ['source_type' => 'order', 'source' => $order->public_id, 'source_version' => 1]));
        $this->issue($order);
        $this->conflict(fn () => $this->issue($order));
        $this->assertSame(1, FinancialDocument::count());
        $this->assertSame(1, FinancialEntry::count());
    }

    public function test_overpayment_zero_amount_and_unallocated_receipts_are_rejected(): void
    {
        $document = $this->issue();
        $this->conflict(fn () => $this->receipt($document, '100.11'));
        $this->conflict(fn () => $this->receipt($document, '0.00'), 422);
        $this->postJson('/admin/finance/receipt', $this->payload(['amount' => '2.00', 'reference' => 'PAY-UNALLOCATED', 'method' => 'cash']))->assertUnprocessable();
        $this->assertSame(0, $document->fresh()->paid_minor);
    }

    public function test_duplicate_evidence_is_case_insensitive_global_and_not_reusable_after_reversal(): void
    {
        $first = $this->issue();
        $second = $this->issue();
        $receipt = $this->receipt($first, '20.00', ['reference' => 'PAY-DUPLICATE']);
        $this->conflict(fn () => $this->receipt($second, '20.00', ['reference' => 'pay-duplicate', 'method' => 'cash']));
        $this->reverse($receipt);
        $this->conflict(fn () => $this->receipt($second, '20.00', ['reference' => 'PAY-DUPLICATE']));
        $this->assertSame(0, $first->fresh()->paid_minor);
        $this->assertSame(0, $second->fresh()->paid_minor);
    }

    public function test_idempotent_retry_binds_actor_target_and_payload_and_rechecks_authority(): void
    {
        $document = $this->issue();
        $data = $this->payload(['document' => $document->public_id, 'version' => $document->version, 'amount' => '30.00', 'reference' => 'PAY-IDEMPOTENT', 'method' => 'cash']);
        $service = app(FinancialOperations::class);
        $id = $service->execute($this->actor, 'receipt', $data);
        $this->assertSame($id, $service->execute($this->actor, 'receipt', $data));
        $this->conflict(fn () => $service->execute($this->actor, 'receipt', array_replace($data, ['amount' => '31.00'])));
        $staff = User::factory()->staff(['finance.view', 'finance.receive', 'finance.details', 'orders.view'])->create();
        $this->conflict(fn () => $service->execute($staff, 'receipt', $data));
        $this->assertSame(3000, $document->fresh()->paid_minor);
    }

    public function test_reversals_and_adjustments_leave_compensating_history(): void
    {
        $document = $this->issue();
        $receipt = $this->receipt($document, '70.00');
        $this->command('credit', ['document' => $document->public_id, 'version' => $document->fresh()->version, 'amount' => '30.10']);
        $this->assertSame('settled', $document->fresh()->settlement());
        $this->conflict(fn () => $this->command('credit', ['document' => $document->public_id, 'version' => $document->fresh()->version, 'amount' => '0.01']));
        $reversal = $this->reverse($receipt);
        $this->assertSame(-7000, $reversal->receipt_delta);
        $this->assertSame('unpaid', $document->fresh()->settlement());
        $this->conflict(fn () => $this->reverse($receipt));
        $this->conflict(fn () => $this->reverse($reversal));
        $this->command('debit', ['document' => $document->public_id, 'version' => $document->fresh()->version, 'amount' => '5.20']);
        $this->assertSame(7520, $document->fresh()->charged_minor);
        $this->assertSame(['charged' => 7520, 'paid' => 0, 'outstanding' => 7520], app(FinancialOperations::class)->reconcile($document->fresh()));
    }

    public function test_reversing_charge_cannot_destroy_paid_receivable(): void
    {
        $document = $this->issue();
        $charge = $document->entries()->firstOrFail();
        $receipt = $this->receipt($document);
        $this->conflict(fn () => $this->reverse($charge));
        $this->reverse($receipt);
        $this->reverse($charge);
        $this->assertSame('cleared', $document->fresh()->settlement());
    }

    public function test_expenses_can_be_standalone_or_repair_related_and_reverse_exactly(): void
    {
        $entry = $this->command('expense', ['amount' => '11.23', 'category' => 'operating', 'reference' => 'EXP-VOUCHER-001', 'method' => 'cash']);
        $this->assertNull($entry->financial_document_id);
        $this->assertSame(1123, $entry->expense_delta);
        $this->reverse($entry);
        $this->assertSame(0, (int) FinancialEntry::sum('expense_delta'));
        $document = $this->issue();
        $entry = $this->command('expense', ['document' => $document->public_id, 'version' => $document->version, 'amount' => '2.00', 'category' => 'parts', 'reference' => 'EXP-PARTS-001', 'method' => 'transfer']);
        $this->assertSame($document->id, $entry->financial_document_id);
        $this->assertSame(10010, $document->fresh()->charged_minor);
    }

    public function test_injected_failure_rolls_back_ledger_projection_operation_and_global_activity(): void
    {
        $document = $this->issue();
        $before = [FinancialEntry::count(), DB::table('financial_operations')->count(), AdminActivity::count(), $document->getAttributes()];
        Event::listen('finance.after-write', fn () => throw new \RuntimeException('Injected synthetic failure'));
        try {
            $this->receipt($document);
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected synthetic failure', $exception->getMessage());
        }
        $this->assertSame($before, [FinancialEntry::count(), DB::table('financial_operations')->count(), AdminActivity::count(), $document->fresh()->getAttributes()]);
    }

    public function test_stale_document_or_reconciliation_drift_blocks_new_posting(): void
    {
        $document = $this->issue();
        $this->receipt($document);
        $this->conflict(fn () => $this->receipt($document, '5.00', ['version' => $document->version]));
        DB::table('financial_documents')->where('id', $document->id)->update(['paid_minor' => 0]);
        $this->conflict(fn () => $this->receipt($document));
        $report = app(FinancialReporting::class)->summary(['from' => now()->toDateString(), 'to' => now()->toDateString()]);
        $this->assertSame(1, $report['discrepancies']);
    }

    public function test_database_and_model_reject_history_changes_and_invalid_balances(): void
    {
        $document = $this->issue();
        $entry = $document->entries()->firstOrFail();
        try {
            $entry->note = 'Overwritten';
            $entry->save();
            $this->fail('Model allowed history update');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        foreach ([fn () => DB::table('financial_entries')->where('id', $entry->id)->update(['charge_delta' => 2]), fn () => DB::table('financial_entries')->where('id', $entry->id)->delete(), fn () => DB::table('financial_documents')->where('id', $document->id)->update(['paid_minor' => 10011]), fn () => DB::table('financial_documents')->where('id', $document->id)->update(['customer_id' => $this->customer()->id])] as $attempt) {
            try {
                $attempt();
                $this->fail('Database permitted invariant violation');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(10010, $document->fresh()->charged_minor);
    }

    public function test_private_evidence_is_encrypted_hidden_and_absent_from_activity_and_summary(): void
    {
        $document = $this->issue();
        $entry = $this->receipt($document, '5.00', ['reference' => 'PRIVATE-TRANSFER-REF']);
        $raw = DB::table('financial_entries')->find($entry->id);
        $this->assertStringNotContainsString('PRIVATE', $raw->reference.$raw->note);
        $this->assertArrayNotHasKey('note', $entry->toArray());
        $this->assertArrayNotHasKey('reference', $entry->toArray());
        $this->assertStringNotContainsString('PRIVATE', AdminActivity::get()->toJson());
        $this->get('/admin/finance')->assertOk()->assertDontSee('PRIVATE-TRANSFER-REF')->assertDontSee('PRIVATE-FINANCIAL-EVIDENCE')->assertDontSee('PRIVATE-FINANCE-CUSTOMER');
        $this->get('/admin/finance/entries/'.$entry->public_id)->assertOk()->assertSee('PRIVATE-TRANSFER-REF');
    }

    public function test_report_totals_filters_and_current_positions_are_exact(): void
    {
        $document = $this->issue();
        $receipt = $this->receipt($document, '25.05');
        $this->command('expense', ['amount' => '4.01', 'category' => 'parts', 'reference' => 'EXP-REPORT-01', 'method' => 'cash']);
        $this->reverse($receipt);
        $reporting = app(FinancialReporting::class);
        $filters = ['from' => now()->toDateString(), 'to' => now()->toDateString()];
        $summary = $reporting->summary($filters);
        $this->assertSame(['charges' => '10010', 'receipts' => '0', 'expenses' => '401', 'outstanding' => '10010', 'settled' => 0, 'unsettled' => 1, 'settled_orders' => 0, 'unsettled_orders' => 1, 'discrepancies' => 0, 'gross_sales_documents' => '10010'], $summary);
        $parts = $reporting->summary($filters + ['category' => 'parts']);
        $this->assertSame('0', $parts['charges']);
        $this->assertSame('401', $parts['expenses']);
        $this->assertSame('10010', $parts['outstanding']);
        $this->get('/admin/finance/reports?from=2025-01-01&to=2026-10-21')->assertUnprocessable();
        $this->get('/admin/finance/reports?category=parts')->assertOk()->assertSee('گزارش مالی و تطبیق');
    }

    public function test_customer_reconciliation_never_moves_financial_history_or_combines_debts(): void
    {
        $document = $this->issue();
        $destination = $this->customer();
        app(CustomerManagement::class)->reconcile($this->actor, $this->customer, ['version' => $this->customer->version, 'destination_version' => $destination->version, 'destination' => $destination->public_id, 'identity_reviewed' => true, 'reason' => 'Synthetic independent identity review']);
        $this->assertSame($this->customer->id, $document->fresh()->customer_id);
        $this->get('/admin/finance/customers/'.$destination->public_id)->assertOk()->assertDontSee(route('admin.finance.documents.show', $document), false);
        $this->get('/admin/finance/customers/'.$this->customer->public_id)->assertOk()->assertSee(route('admin.finance.documents.show', $document), false);
        $this->receipt($document);
        $this->assertSame(2001, $document->fresh()->paid_minor);
    }

    public function test_password_failure_does_not_flash_financial_evidence_and_persisted_password_is_used(): void
    {
        $document = $this->issue();
        $this->from('/admin/finance')->post('/admin/finance/receipt', $this->payload(['password' => 'wrong', 'document' => $document->public_id, 'version' => $document->version, 'amount' => '5.00', 'method' => 'cash', 'reference' => 'PRIVATE-WRONG-PASSWORD']))->assertSessionHasErrors('password')->assertSessionMissing('_old_input');
        User::query()->where('id', $this->actor->id)->update(['password' => bcrypt('new-current-password')]);
        $this->expectException(ValidationException::class);
        $this->receipt($document);
    }

    public static function permissions(): array
    {
        return [['issue', 'finance.charge'], ['receipt', 'finance.receive'], ['expense', 'finance.expenses'], ['credit', 'finance.correct'], ['debit', 'finance.correct'], ['reverse', 'finance.correct']];
    }

    #[DataProvider('permissions')]
    public function test_direct_write_requests_deny_missing_permission(string $action, string $permission): void
    {
        $staff = User::factory()->staff(['finance.view', 'finance.details', 'orders.view'])->create();
        $this->actingAs($staff)->post('/admin/finance/'.$action, [])->assertForbidden();
        $this->get('/admin/finance/new/'.$action)->assertForbidden();
        $this->assertSame(0, FinancialEntry::count());
    }

    public function test_staff_permissions_privacy_and_revocation_are_enforced(): void
    {
        $document = $this->issue();
        $entry = $this->receipt($document);
        $reader = User::factory()->staff(['finance.view'])->create();
        $this->actingAs($reader)->get('/admin/finance')->assertOk()->assertDontSee('PRIVATE');
        $this->get('/admin/finance/entries/'.$entry->public_id)->assertForbidden();
        $this->get('/admin/finance/customers/'.$this->customer->public_id)->assertForbidden();
        $writer = User::factory()->staff(['finance.view', 'finance.details', 'finance.receive', 'orders.view'])->create();
        $this->actingAs($writer)->post('/admin/finance/receipt', $this->payload(['document' => $document->public_id, 'version' => $document->fresh()->version, 'amount' => '1.00', 'method' => 'cash', 'reference' => 'PAY-STAFF-01']))->assertRedirect();
        $account = AdminAccount::where('user_id', $writer->id)->firstOrFail();
        $account->permissions = ['finance.view'];
        $account->session_version++;
        $account->save();
        $this->post('/admin/finance/receipt', [])->assertRedirect(route('admin.login'));
        $this->actingAs($writer)->post('/admin/finance/receipt', [])->assertForbidden();
        $account->is_active = false;
        $account->save();
        $this->get('/admin/finance')->assertRedirect(route('admin.login'));
    }

    public function test_finance_grants_do_not_bypass_source_access_or_customer_contact_permissions(): void
    {
        $document = $this->issue();
        $writer = User::factory()->staff(['finance.view', 'finance.details', 'finance.receive', 'finance.balances', 'customers.view'])->create();
        $this->actingAs($writer)->post('/admin/finance/receipt', $this->payload(['document' => $document->public_id, 'version' => $document->version, 'amount' => '1.00', 'method' => 'cash', 'reference' => 'PAY-NOSOURCE-01']))->assertForbidden();
        $this->get('/admin/finance/customers/'.$this->customer->public_id)->assertOk()->assertDontSee('PRIVATE-FINANCE-CUSTOMER');
        $this->get('/admin/finance/entries/'.Str::uuid())->assertNotFound();
        $this->get('/admin/finance/documents/'.$document->id)->assertNotFound();
    }

    public function test_all_finance_routes_are_private_and_ordinary_users_are_denied(): void
    {
        $this->get('/admin/finance')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->withSession(['admin_session_version' => null]);
        $this->actingAs(User::factory()->create())->get('/admin/finance')->assertForbidden();
        $this->post('/admin/finance/issue', [])->assertForbidden();
        $this->get('/admin/finance/reports')->assertForbidden();
    }

    public function test_repair_finance_requires_completed_work_current_approval_and_independent_customer(): void
    {
        $service = app(RepairOperations::class);
        $case = $service->intake($this->actor, ['submission_token' => bin2hex(random_bytes(32)), 'physical_receipt_confirmed' => true, 'customer_name' => 'PRIVATE-REPAIR-FINANCE', 'contact_phone' => '09000000012', 'device_brand' => 'PAX', 'device_model' => 'Synthetic', 'device_serial' => 'FINANCE-REPAIR-001', 'issue' => 'Synthetic fault', 'physical_condition' => 'Synthetic intact', 'accessories' => 'Synthetic cable', 'received_at' => RepairTime::input(now()->subHour())]);
        $case->customer_id = $this->customer->id;
        $case->save();
        $case->refresh();
        $operation = function (string $action, array $data) use ($service, &$case): void {
            $case = $service->update($this->actor, $case, $action, ['version' => $case->version] + $data);
        };
        $operation('assign', ['technician_id' => $this->actor->id]);
        $operation('transition', ['status' => 'waiting_inspection']);
        $operation('transition', ['status' => 'inspecting']);
        $operation('inspect', ['findings' => 'Synthetic findings', 'diagnosis' => 'Synthetic diagnosis', 'proposed_actions' => 'Synthetic work', 'complete' => true]);
        $operation('estimate', ['customer_summary' => 'Synthetic approved scope', 'service_cost' => 120, 'parts_cost' => 30]);
        $estimate = $case->estimates()->latest('revision')->firstOrFail();
        $operation('decision', ['estimate_id' => $estimate->id, 'decision' => 'approved', 'channel' => 'phone', 'evidence' => 'Synthetic consent', 'staff_recorded_confirmation' => true]);
        $this->assertSame(0, FinancialEntry::count());
        $issue = fn () => $this->command('issue', ['source_type' => 'repair', 'source' => $case->public_id, 'source_version' => $case->version]);
        $this->conflict($issue);
        $operation('transition', ['status' => 'repairing']);
        $operation('work', ['work_performed' => 'Synthetic completed work', 'parts_replaced' => 'Synthetic part', 'complete' => true, 'completion_checks' => 'Synthetic passed checks', 'final_result' => 'Synthetic repaired']);
        $entry = $this->command('issue', ['source_type' => 'repair', 'source' => $case->public_id, 'source_version' => $case->version]);
        $document = FinancialDocument::findOrFail($entry->financial_document_id);
        $this->assertSame(15000, $document->source_amount_minor);
        $this->assertSame('repair', $entry->category);
        $this->receipt($document, '50.00');
        $this->command('expense', ['document' => $document->public_id, 'version' => $document->fresh()->version, 'amount' => '12.00', 'method' => 'cash', 'category' => 'parts', 'reference' => 'EXP-REPAIR-FINANCE-001']);
        $this->get('/admin/finance/sources/repair/'.$case->public_id)->assertRedirect(route('admin.finance.documents.show', $document));
        $this->get('/admin/finance/documents/'.$document->public_id)->assertOk()->assertSee('مالی تعمیر');
        $destination = $this->customer();
        app(CustomerManagement::class)->reconcile($this->actor, $this->customer, ['version' => $this->customer->version, 'destination_version' => $destination->version, 'destination' => $destination->public_id, 'identity_reviewed' => true, 'reason' => 'Synthetic reviewed identity']);
        $this->assertSame($destination->id, $case->fresh()->customer_id);
        $this->assertSame($this->customer->id, $document->fresh()->customer_id);
    }

    public function test_finance_forms_and_transaction_screens_render_and_escape_private_notes(): void
    {
        $unissued = $this->order();
        $this->get('/admin/finance/sources/order/'.$unissued->public_id)->assertOk()->assertSee('autocomplete="current-password"', false);
        $document = $this->issue();
        $entry = $this->receipt($document, '1.00', ['reason' => '<script>PRIVATE-XSS</script>']);
        foreach (['issue', 'receipt', 'expense', 'credit', 'debit'] as $action) {
            $this->get('/admin/finance/new/'.$action.'?document='.$document->public_id)->assertOk()->assertSee('name="password"', false)->assertSee('name="operation_key"', false);
        }
        $this->get('/admin/finance/new/reverse?entry='.$entry->public_id)->assertOk()->assertSee('name="version"', false);
        $this->get('/admin/finance/entries/'.$entry->public_id)->assertOk()->assertSee('&lt;script&gt;PRIVATE-XSS&lt;/script&gt;', false)->assertDontSee('<script>PRIVATE-XSS</script>', false);
        $this->from('/admin/finance')->post('/admin/finance/receipt', $this->payload(['reference' => 'PRIVATE-INVALID']))->assertSessionHasErrors()->assertSessionMissing('_old_input');
    }

    public function test_failed_financial_form_associates_errors_without_replaying_private_values(): void
    {
        $form = '/admin/finance/new/expense';
        $this->from($form)->post('/admin/finance/expense', $this->payload(['amount' => 'invalid', 'method' => 'cash', 'category' => 'parts', 'reference' => 'PRIVATE-INVALID']))
            ->assertRedirect($form)->assertSessionHasErrors('amount')->assertSessionMissing('_old_input');
        // Carry the browser's session cookie across the validation redirect.
        $this->withCookie(config('session.cookie'), session()->getId())->get($form)->assertOk()->assertSee('aria-describedby="finance-amount-error"', false)
            ->assertSee('aria-invalid="true"', false)->assertSee('inputmode="decimal"', false)
            ->assertSee('autocomplete="current-password"', false)->assertDontSee('PRIVATE-INVALID')->assertDontSee('PRIVATE-FINANCIAL-EVIDENCE');
    }
}
