<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CommerceInput
{
    public static function rules(string $action, bool $existing = false): array
    {
        $reason = ['required', 'string', 'min:5', 'max:1000'];
        $rules = ['operation_key' => ['required', 'uuid']];
        if ($existing) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }

        return $rules + match ($action) {
            'item.create' => ['sku' => ['required', 'string', 'regex:/\A[A-Z0-9][A-Z0-9._-]{1,63}\z/'], 'name' => ['required', 'string', 'min:2', 'max:150'], 'condition' => ['required', Rule::in(['new', 'used'])], 'serialized' => ['required', 'boolean'], 'product_id' => ['nullable', 'integer', 'exists:products,id'], 'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:1000000'], 'reason' => $reason],
            'item.configure' => ['low_stock_threshold' => ['required', 'integer', 'min:0', 'max:1000000'], 'reason' => $reason],
            'item.receive', 'item.adjust' => ['quantity' => ['required', 'integer', $action === 'item.receive' ? 'min:1' : 'min:-10000', 'max:10000', 'not_in:0'], 'serials' => ['nullable', 'string', 'max:11000'], 'reason' => $reason],
            'order.save' => ['customer' => ['required', 'uuid'], 'lines' => ['required', 'array', 'min:1', 'max:30'], 'lines.*' => ['required', 'array:item,quantity,unit_price'], 'lines.*.item' => ['required', 'uuid', 'distinct'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'], 'lines.*.unit_price' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'], 'reason' => $reason],
            'order.reserve', 'order.release', 'order.cancel', 'order.confirm' => ['reason' => $reason],
            'order.fulfill' => ['reason' => $reason, 'fulfillment_info' => ['required', 'string', 'min:5', 'max:2000'], 'partial_quantity' => ['prohibited'], 'lines' => ['prohibited']],
            default => abort(404),
        };
    }

    public static function validate(string $action, array $data, bool $existing = false): array
    {
        return Validator::make($data, self::rules($action, $existing), ['required' => 'تکمیل :attribute ضروری است.', 'min' => ':attribute خارج از محدوده است.', 'max' => ':attribute بیش از حد مجاز است.', 'regex' => 'قالب :attribute معتبر نیست.', 'prohibited' => 'تحویل جزئی پشتیبانی نمی‌شود.'], self::attributes())->validate();
    }

    public static function attributes(): array
    {
        return ['reason' => 'دلیل عملیات', 'customer' => 'شناسهٔ مشتری', 'quantity' => 'تعداد', 'sku' => 'کد کالا', 'name' => 'عنوان کالا', 'version' => 'نسخه', 'operation_key' => 'شناسهٔ عملیات', 'fulfillment_info' => 'اطلاعات تحویل', 'lines' => 'ردیف‌های سفارش', 'lines.*.unit_price' => 'قیمت واحد', 'lines.*.quantity' => 'تعداد ردیف'];
    }
}
