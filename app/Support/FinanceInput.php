<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class FinanceInput
{
    public const METHODS = ['cash' => 'نقدی', 'transfer' => 'انتقال بانکی', 'terminal' => 'کارتخوان فروشگاه'];

    public const CATEGORIES = ['sales' => 'فروش', 'repair' => 'تعمیر', 'adjustment' => 'اصلاح مبلغ', 'parts' => 'قطعات', 'labor' => 'دستمزد تعمیر', 'supplies' => 'ملزومات', 'operating' => 'هزینهٔ جاری', 'other' => 'سایر هزینه‌ها'];

    public const KINDS = ['charge' => 'صدور فاکتور', 'receipt' => 'دریافت دستی', 'expense' => 'هزینه', 'credit' => 'کاهش مبلغ', 'debit' => 'افزایش مبلغ', 'reversal' => 'لغو و اصلاح ثبت'];

    public const STATES = ['unpaid' => 'پرداخت‌نشده', 'partial' => 'پرداخت جزئی', 'settled' => 'تسویه‌شده', 'cleared' => 'بدهی صفرشده'];

    public static function methodLabel(?string $method): string
    {
        return ['gateway' => 'پرداخت آنلاین تأییدشده', 'card_transfer' => 'کارت‌به‌کارت با تأیید همکار', 'in_store' => 'دریافت در فروشگاه'][$method ?? ''] ?? (self::METHODS[$method ?? ''] ?? 'رویداد غیرپرداختی');
    }

    public static function rules(string $action): array
    {
        return ['operation_key' => ['required', 'uuid'], 'password' => ['required', 'string', 'max:255'], 'reason' => ['required', 'string', 'min:5', 'max:1500']] + match ($action) {
            'issue' => ['source_type' => ['required', Rule::in(['order', 'repair'])], 'source' => ['required', 'uuid'], 'source_version' => ['required', 'integer', 'min:1']],
            'receipt', 'expense' => ['document' => [$action === 'receipt' ? 'required' : 'nullable', 'uuid'], 'version' => ['nullable', 'integer', 'min:1'], 'amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/'], 'method' => ['required', Rule::in(array_keys(self::METHODS))], 'reference' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._\/-]{5,79}\z/'], 'category' => [$action === 'expense' ? 'required' : 'nullable', Rule::in(['parts', 'labor', 'supplies', 'operating', 'other'])]],
            'credit', 'debit' => ['document' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'], 'amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/']],
            'reverse' => ['entry' => ['required', 'uuid'], 'version' => ['nullable', 'integer', 'min:1']],
            default => ['invalid_action' => ['required', 'prohibited']],
        };
    }

    public static function validate(string $action, array $input): array
    {
        return Validator::make(self::normalize($input), self::rules($action))->validate();
    }

    public static function normalize(array $input): array
    {
        if (isset($input['amount']) && is_string($input['amount'])) {
            $input['amount'] = FinanceMoney::normalize($input['amount']);
        }
        if (isset($input['reference']) && is_string($input['reference'])) {
            $input['reference'] = strtoupper(trim($input['reference']));
        }

        return $input;
    }
}
