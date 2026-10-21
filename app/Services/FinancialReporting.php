<?php

namespace App\Services;

use App\Models\FinancialDocument;
use App\Models\FinancialEntry;

final class FinancialReporting
{
    public function entries(array $filters)
    {
        $query = FinancialEntry::query()->whereBetween('created_at', [$filters['from'].' 00:00:00', $filters['to'].' 23:59:59']);
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        return $query;
    }

    public function summary(array $filters): array
    {
        $period = $this->entries($filters)->selectRaw('COALESCE(SUM(charge_delta), 0) AS charges, COALESCE(SUM(receipt_delta), 0) AS receipts, COALESCE(SUM(expense_delta), 0) AS expenses')->first();
        $positions = FinancialDocument::query()->selectRaw('COALESCE(SUM(charged_minor - paid_minor), 0) AS outstanding, COUNT(CASE WHEN charged_minor > 0 AND paid_minor = charged_minor THEN 1 END) AS settled, COUNT(CASE WHEN charged_minor > paid_minor THEN 1 END) AS unsettled, COUNT(CASE WHEN sales_order_id IS NOT NULL AND charged_minor > 0 AND paid_minor = charged_minor THEN 1 END) AS settled_orders, COUNT(CASE WHEN sales_order_id IS NOT NULL AND charged_minor > paid_minor THEN 1 END) AS unsettled_orders')->first();
        $ledger = FinancialEntry::query()->selectRaw('financial_document_id, SUM(charge_delta) AS charges, SUM(receipt_delta) AS receipts')->whereNotNull('financial_document_id')->groupBy('financial_document_id');
        $discrepancies = FinancialDocument::query()->leftJoinSub($ledger, 'ledger', 'ledger.financial_document_id', '=', 'financial_documents.id')->whereRaw('(charged_minor <> COALESCE(ledger.charges, 0) OR paid_minor <> COALESCE(ledger.receipts, 0))')->count();
        $gross = FinancialDocument::query()->whereNotNull('sales_order_id')->whereBetween('created_at', [$filters['from'].' 00:00:00', $filters['to'].' 23:59:59'])->sum('source_amount_minor');

        return ['charges' => (string) $period->charges, 'receipts' => (string) $period->receipts, 'expenses' => (string) $period->expenses, 'outstanding' => (string) $positions->outstanding, 'settled' => (int) $positions->settled, 'unsettled' => (int) $positions->unsettled, 'settled_orders' => (int) $positions->settled_orders, 'unsettled_orders' => (int) $positions->unsettled_orders, 'discrepancies' => $discrepancies, 'gross_sales_documents' => (string) $gross];
    }
}
