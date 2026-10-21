<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Enums\RepairStage;
use App\Models\AdminAccount;
use App\Models\Customer;
use App\Models\FinancialDocument;
use App\Models\FinancialEntry;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\AdminHistory;
use App\Support\FinanceAccess;
use App\Support\FinanceInput;
use App\Support\FinanceMoney;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FinancialOperations
{
    public function execute(User $actor, string $action, array $input): int
    {
        FinanceAccess::authorize($actor, $action);
        $data = FinanceInput::validate($action, $input);
        $payload = $data;
        unset($payload['password']);
        ksort($payload);
        $digest = hash_hmac('sha256', json_encode([$action, $payload], JSON_THROW_ON_ERROR), config('app.key'));
        try {
            return DB::transaction(function () use ($actor, $action, $data, $digest): int {
                DB::table('financial_operations')->insertOrIgnore(['operation_key' => $data['operation_key'], 'actor_id' => $actor->id, 'action' => $action, 'payload_digest' => $digest, 'created_at' => now()]);
                $operation = DB::table('financial_operations')->where('operation_key', $data['operation_key'])->lockForUpdate()->first();
                // Serialize against staff revocation/password reset, then recheck persisted grants.
                $account = AdminAccount::query()->where('user_id', $actor->id)->lockForUpdate()->first();
                $persisted = User::query()->lockForUpdate()->findOrFail($actor->id);
                FinanceAccess::authorize($persisted, $action);
                abort_unless($account && ! $account->must_change_password, 403);
                if (! Hash::check($data['password'], $persisted->password)) {
                    throw ValidationException::withMessages(['password' => 'گذرواژهٔ فعلی معتبر نیست.']);
                }
                abort_unless($operation && $operation->actor_id === $actor->id && $operation->action === $action && hash_equals($operation->payload_digest, $digest), 409, 'شناسهٔ عملیات برای درخواست دیگری استفاده شده است.');
                if ($operation->result_id !== null) {
                    return (int) $operation->result_id;
                }
                if ($action === 'issue') {
                    $document = $this->issue($persisted, $data);
                    $charge = $document->source_amount_minor;
                    $receipt = $expense = 0;
                    $kind = 'charge';
                    $category = $document->sales_order_id ? 'sales' : 'repair';
                    $original = null;
                } else {
                    $original = $action === 'reverse' ? FinancialEntry::query()->where('public_id', $data['entry'])->lockForUpdate()->firstOrFail() : null;
                    $query = FinancialDocument::query()->lockForUpdate();
                    $document = $original?->financial_document_id ? $query->findOrFail($original->financial_document_id) : (! empty($data['document']) ? $query->where('public_id', $data['document'])->firstOrFail() : null);
                    if ($document) {
                        FinanceAccess::source($persisted, $document->sales_order_id ? 'order' : 'repair');
                        abort_unless(isset($data['version']) && $document->version === (int) $data['version'], 409, 'سند تغییر کرده است؛ اطلاعات را تازه کنید.');
                        $this->reconcile($document);
                    }
                    abort_if(! $document && in_array($action, ['receipt', 'debit', 'credit'], true), 422, 'سند معتبر انتخاب کنید.');
                    if ($original) {
                        abort_if($original->kind === 'reversal' || FinancialEntry::query()->where('reversal_of', $original->id)->exists(), 409, 'این ثبت قبلاً برگشت خورده یا خود برگشت است.');
                        $charge = -$original->charge_delta;
                        $receipt = -$original->receipt_delta;
                        $expense = -$original->expense_delta;
                        $category = $original->category;
                        $kind = 'reversal';
                    } else {
                        $amount = FinanceMoney::minor($data['amount']);
                        abort_unless($amount > 0, 422, 'مبلغ باید مثبت باشد.');
                        $charge = match ($action) {
                            'debit' => $amount, 'credit' => -$amount, default => 0
                        };
                        $receipt = $action === 'receipt' ? $amount : 0;
                        $expense = $action === 'expense' ? $amount : 0;
                        $category = $action === 'expense' ? $data['category'] : ($action === 'receipt' ? ($document->sales_order_id ? 'sales' : 'repair') : 'adjustment');
                        $kind = $action;
                    }
                }
                if ($document) {
                    $document->charged_minor += $charge;
                    $document->paid_minor += $receipt;
                    abort_if($document->paid_minor < 0 || $document->charged_minor < $document->paid_minor || $document->charged_minor > 999999999999999, 409, 'ثبت موجب اضافه‌پرداخت یا ماندهٔ نامعتبر می‌شود.');
                    $document->version++;
                    $document->save();
                }
                $entry = new FinancialEntry;
                foreach (['public_id' => (string) Str::uuid(), 'financial_operation_id' => $operation->id, 'financial_document_id' => $document?->id, 'actor_id' => $actor->id, 'reversal_of' => $original?->id, 'kind' => $kind, 'category' => $category, 'charge_delta' => $charge, 'receipt_delta' => $receipt, 'expense_delta' => $expense, 'note' => $data['reason'], 'created_at' => now()] as $key => $value) {
                    $entry->$key = $value;
                }
                if (in_array($action, ['receipt', 'expense'], true)) {
                    $reference = strtoupper($data['reference']);
                    abort_if(preg_match('/\A[0-9]{13,19}\z/', $reference), 422, 'شمارهٔ کارت ثبت نکنید؛ شناسهٔ مدرک با پیشوند وارد کنید.');
                    $entry->reference = $reference;
                    $entry->reference_digest = hash_hmac('sha256', $reference, config('app.key'));
                    $entry->method = $data['method'];
                }
                $entry->save();
                event('finance.after-write', [$action, $entry->id]);
                DB::table('financial_operations')->where('id', $operation->id)->update(['result_id' => $entry->id]);
                AdminHistory::record(AdminAction::FinancePosted, 'financial_entry', $entry->id, $actor->id);

                return $entry->id;
            }, 3);
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? '', ['23000', '23505', '40001'], true) || in_array($exception->errorInfo[1] ?? 0, [1205, 1213], true)) {
                abort(409, 'مدرک تکراری یا عملیات هم‌زمان است؛ ثبت قبلی را بررسی کنید.');
            }
            throw $exception;
        }
    }

    private function issue(User $actor, array $data): FinancialDocument
    {
        FinanceAccess::source($actor, $data['source_type']);
        // Same customer -> source order as CRM linking; retry handles concurrent identity changes.
        $class = $data['source_type'] === 'order' ? SalesOrder::class : RepairCase::class;
        $source = $class::query()->where('public_id', $data['source'])->firstOrFail();
        abort_unless($source->customer_id, 409, 'ابتدا هویت و اتصال پروفایل مشتری را تأیید کنید.');
        $customer = Customer::query()->lockForUpdate()->findOrFail($source->customer_id);
        $source = $class::query()->where('public_id', $data['source'])->lockForUpdate()->firstOrFail();
        abort_unless($source->customer_id === $customer->id && $customer->is_active && $customer->merged_into_id === null && $source->version === (int) $data['source_version'], 409, 'هویت یا نسخهٔ منبع تغییر کرده است.');
        $sourceColumn = $data['source_type'] === 'order' ? 'sales_order_id' : 'repair_case_id';
        abort_if(FinancialDocument::query()->where($sourceColumn, $source->id)->exists(), 409, 'برای این منبع قبلاً سند صادر شده است.');
        if ($source instanceof SalesOrder) {
            abort_unless($source->status === 'fulfilled' && $source->currency === 'TOMAN', 409, 'فقط سفارش تحویل‌شده مبنای صدور این سند است.');
            $amount = FinanceMoney::minor($source->total);
        } else {
            abort_unless(in_array($source->status, [RepairStage::Repaired, RepairStage::Ready, RepairStage::Delivered], true), 409, 'تعمیر باید تکمیل شده باشد.');
            $estimate = $source->estimates()->with('decision')->latest('revision')->first();
            abort_unless($estimate && $estimate->decisionStatus() === 'approved' && (int) $estimate->repair_inspection_id === (int) $source->inspections()->max('id'), 409, 'آخرین برآورد تأییدشده و بررسی سازگار لازم است.');
            $amount = $estimate->total() * 100;
        }
        abort_unless($amount > 0 && $amount <= 999999999999999, 422, 'سند مطالبه به مبلغ مثبت و معتبر نیاز دارد.');
        $document = new FinancialDocument;
        foreach (['public_id' => (string) Str::uuid(), 'customer_id' => $customer->id, $sourceColumn => $source->id, 'source_version' => $source->version, 'source_amount_minor' => $amount, 'charged_minor' => 0, 'paid_minor' => 0, 'version' => 1, 'created_by' => $actor->id, 'created_at' => now()] as $key => $value) {
            $document->$key = $value;
        }
        $document->save();

        return $document;
    }

    public function reconcile(FinancialDocument $document): array
    {
        $sums = $document->entries()->selectRaw('COALESCE(SUM(charge_delta), 0) AS charged, COALESCE(SUM(receipt_delta), 0) AS paid')->first();
        $valid = (string) $sums->charged === (string) $document->charged_minor && (string) $sums->paid === (string) $document->paid_minor;
        abort_unless($valid, 409, 'مغایرت دفتر مالی؛ عملیات متوقف شد. برای بررسی مدیر کل ارجاع دهید.');

        return ['charged' => (int) $sums->charged, 'paid' => (int) $sums->paid, 'outstanding' => $document->charged_minor - $document->paid_minor];
    }
}
