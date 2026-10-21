<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RepairStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinanceRequest;
use App\Models\Customer;
use App\Models\FinancialDocument;
use App\Models\FinancialEntry;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Services\FinancialOperations;
use App\Services\FinancialReporting;
use App\Support\AdminAccess;
use App\Support\FinanceAccess;
use App\Support\FinanceInput;
use App\Support\FinanceMoney;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FinanceController extends Controller
{
    private function screen(string $name, array $data)
    {
        // A single persisted snapshot for presentation; routes and writes reauthorize.
        $account = AdminAccess::account(auth()->user());
        $financeAllows = fn (string $permission): bool => $account && ($account->isSuperAdmin() || in_array($permission, $account->permissions ?? [], true));

        return view($name, $data + ['financeAllows' => $financeAllows]);
    }

    public function index(Request $request, FinancialReporting $reporting)
    {
        $filters = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'category' => ['nullable', Rule::in(array_keys(FinanceInput::CATEGORIES))]]);
        $filters['from'] = $filters['from'] ?? now()->startOfMonth()->format('Y-m-d');
        $filters['to'] = $filters['to'] ?? now()->format('Y-m-d');
        abort_unless($filters['from'] <= $filters['to'] && CarbonImmutable::parse($filters['from'])->diffInDays($filters['to']) <= 366, 422, 'بازهٔ گزارش باید حداکثر ۳۶۶ روز باشد.');

        $isReport = $request->routeIs('admin.finance.reports');
        $data = ['filters' => $filters, 'summary' => $reporting->summary($filters), 'entries' => $reporting->entries($filters)->latest('id')->paginate(25, ['id', 'public_id', 'financial_document_id', 'kind', 'category', 'charge_delta', 'receipt_delta', 'expense_delta', 'actor_id', 'created_at'])->appends($filters)];
        if (! $isReport) {
            $data['documents'] = FinancialDocument::query()->latest('id')->paginate(20, ['*'], 'documents_page');
        }

        return $this->screen($isReport ? 'admin.finance.reports' : 'admin.finance.index', $data);
    }

    public function create(Request $request, string $action)
    {
        FinanceAccess::authorize($request->user(), $action);
        $input = $request->validate(['document' => ['nullable', 'uuid'], 'entry' => ['nullable', 'uuid']]);
        $document = ! empty($input['document']) ? FinancialDocument::query()->where('public_id', $input['document'])->firstOrFail() : null;
        $entry = ! empty($input['entry']) ? FinancialEntry::query()->where('public_id', $input['entry'])->firstOrFail(['id', 'public_id', 'financial_document_id', 'kind']) : null;
        if ($entry) {
            $document = $entry->financial_document_id ? FinancialDocument::findOrFail($entry->financial_document_id) : null;
        }
        if ($document) {
            FinanceAccess::source($request->user(), $document->sales_order_id ? 'order' : 'repair');
        }

        return $this->screen('admin.finance.form', compact('action', 'document', 'entry'));
    }

    public function store(FinanceRequest $request, FinancialOperations $operations)
    {
        try {
            $id = $operations->execute($request->user(), $request->operation(), $request->validated());
        } catch (ValidationException $exception) {
            // Service reauthentication errors must not replay financial input into a session.
            if ($request->expectsJson()) {
                throw $exception;
            }

            return redirect()->back()->withErrors($exception->errors());
        }
        $entry = FinancialEntry::findOrFail($id);

        return redirect()->route($entry->financial_document_id ? 'admin.finance.documents.show' : 'admin.finance.index', $entry->financial_document_id ? FinancialDocument::findOrFail($entry->financial_document_id) : [])->with('success', 'ثبت مالی انجام شد؛ این ثبت دستی تأیید بانکی نیست.');
    }

    public function document(FinancialDocument $document, FinancialOperations $operations)
    {
        $valid = true;
        try {
            $operations->reconcile($document);
        } catch (HttpException) {
            $valid = false;
        }
        $customer = Gate::allows('finance.balances') && Gate::allows('customers.view') ? Customer::query()->findOrFail($document->customer_id, ['id', 'public_id']) : null;
        $sourceCustomer = $document->sales_order_id ? SalesOrder::query()->whereKey($document->sales_order_id)->value('customer_id') : RepairCase::query()->whereKey($document->repair_case_id)->value('customer_id');

        return $this->screen('admin.finance.document', ['document' => $document, 'customer' => $customer, 'valid' => $valid, 'sourceCustomerChanged' => (int) $sourceCustomer !== $document->customer_id, 'entries' => $document->entries()->latest('id')->paginate(25, ['id', 'public_id', 'kind', 'category', 'charge_delta', 'receipt_delta', 'expense_delta', 'reversal_of', 'actor_id', 'created_at'])]);
    }

    public function entry(FinancialEntry $entry)
    {
        return $this->screen('admin.finance.entry', ['entry' => $entry, 'document' => $entry->financial_document_id ? FinancialDocument::findOrFail($entry->financial_document_id) : null, 'reversal' => FinancialEntry::query()->where('reversal_of', $entry->id)->first(['public_id'])]);
    }

    public function customer(Customer $customer)
    {
        $customer = Customer::query()->findOrFail($customer->id, Gate::allows('customers.contact') ? ['id', 'public_id', 'name', 'is_active', 'merged_into_id'] : ['id', 'public_id', 'is_active', 'merged_into_id']);
        $query = FinancialDocument::query()->where('customer_id', $customer->id);
        $totals = (clone $query)->selectRaw('COALESCE(SUM(charged_minor), 0) AS charged, COALESCE(SUM(paid_minor), 0) AS paid, COALESCE(SUM(charged_minor - paid_minor), 0) AS outstanding')->first();

        return $this->screen('admin.finance.customer', ['customer' => $customer, 'totals' => $totals, 'documents' => $query->latest('id')->paginate(20), 'entries' => FinancialEntry::query()->whereIn('financial_document_id', FinancialDocument::query()->where('customer_id', $customer->id)->select('id'))->latest('id')->paginate(25, ['id', 'public_id', 'kind', 'category', 'charge_delta', 'receipt_delta', 'expense_delta', 'created_at', 'actor_id'], 'transactions_page')]);
    }

    public function source(Request $request, string $type, string $reference)
    {
        abort_unless(in_array($type, ['order', 'repair'], true), 404);
        FinanceAccess::source($request->user(), $type);
        $class = $type === 'order' ? SalesOrder::class : RepairCase::class;
        $source = $class::query()->where('public_id', $reference)->firstOrFail($type === 'order' ? ['id', 'public_id', 'version', 'status', 'total'] : ['id', 'public_id', 'version', 'status']);
        $document = FinancialDocument::query()->where($type === 'order' ? 'sales_order_id' : 'repair_case_id', $source->id)->first();
        $amount = null;
        if ($source instanceof SalesOrder && $source->status === 'fulfilled') {
            $amount = FinanceMoney::minor($source->total);
        } elseif ($source instanceof RepairCase && in_array($source->status, [RepairStage::Repaired, RepairStage::Ready, RepairStage::Delivered], true)) {
            $estimate = $source->estimates()->with('decision:id,repair_estimate_id,decision')->latest('revision')->first(['id', 'repair_case_id', 'revision', 'service_cost', 'parts_cost']);
            if ($estimate?->decisionStatus() === 'approved') {
                $amount = $estimate->total() * 100;
            }
        }

        return $document ? redirect()->route('admin.finance.documents.show', $document) : $this->screen('admin.finance.source', compact('source', 'type', 'amount'));
    }
}
