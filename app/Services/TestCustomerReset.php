<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Enums\RepairRequestStatus;
use App\Models\CustomerAccount;
use App\Support\RepairIdentity;
use Illuminate\Support\Facades\DB;

final class TestCustomerReset
{
    public function scope(CustomerAccount $account, bool $forUpdate = false): array
    {
        $read = static fn (string $table) => DB::table($table)->when($forUpdate, fn ($query) => $query->lockForUpdate());
        $owned = static fn (string $table) => $read($table)->where('customer_account_id', $account->id);
        $requests = $owned('repair_requests')->orderBy('id')->get(['id', 'customer_id', 'status', 'created_at', 'tracking_reference']);
        $requestIds = $requests->pluck('id')->all();
        $addressIds = $owned('customer_addresses')->orderBy('id')->pluck('id')->all();
        $reason = null;
        if (! app(CustomerLogin::class)->isTestAccount($account) || $account->customer_id !== null) {
            $reason = 'حساب آزمایشی معتبر نیست یا به پروندهٔ مشتری متصل است.';
        } elseif ($requests->contains(fn ($record) => $record->customer_id !== null
            || $record->status !== RepairRequestStatus::OnlineSubmitted->value || ! $record->created_at
            || $record->created_at < $account->getRawOriginal('created_at'))) {
            $reason = 'یکی از درخواست‌ها خارج از ثبت‌نام آزمایشی ایجاد یا به مشتری واقعی متصل شده است.';
        } elseif ($read('repair_cases')->where(fn ($query) => $query->where('customer_account_id', $account->id)->orWhereIn('repair_request_id', $requestIds))->first(['id'])) {
            $reason = 'برای این حساب یا درخواست آن پروندهٔ پذیرش فیزیکی وجود دارد.';
        } elseif ($read('admin_activities')->where('target_type', 'request')->whereIn('target_id', $requestIds)
            ->whereIn('action', [AdminAction::CustomerLinked->value, AdminAction::CustomerUnlinked->value])->first(['id'])) {
            $reason = 'یکی از درخواست‌ها سابقهٔ اتصال به پروندهٔ مشتری دارد.';
        }
        foreach ([
            'sales_orders' => 'سفارش', 'sales_order_events' => 'سابقهٔ سفارش',
            'payment_attempts' => 'تلاش پرداخت', 'payment_receipts' => 'رسید پرداخت',
            'delivery_plans' => 'برنامهٔ تحویل', 'delivery_events' => 'سابقهٔ تحویل',
            'chat_customer_owners' => 'گفت‌وگوی پشتیبانی',
        ] as $table => $label) {
            if ($reason === null && $owned($table)->first(['customer_account_id'])) {
                $reason = 'این حساب '.$label.' دارد. بازنشانی انجام نمی‌شود.';
            }
        }
        $notificationIds = [];
        if ($reason === null) {
            $keys = $requests->map(fn ($record) => CustomerNotifications::eventKey('repair_registered', $record->tracking_reference))->all();
            foreach ($owned('customer_notifications')->orderBy('id')->get() as $item) {
                if ($item->event !== 'repair_registered' || $item->path !== '/account' || ! in_array($item->event_key, $keys, true)
                    || ! in_array($item->state, ['pending', 'cancelled'], true) || $item->sent_at !== null
                    || ($item->state === 'pending' && $item->claimed_at !== null)) {
                    $reason = 'سابقهٔ اعلان با درخواست آزمایشی منطبق نیست یا ارسال آن تعیین تکلیف نشده است.';
                    break;
                }
                $notificationIds[] = $item->id;
            }
        }

        return [
            'requests' => $requestIds,
            'addresses' => $addressIds,
            'notifications' => $notificationIds,
            'reason' => $reason,
            'pin' => RepairIdentity::digest('test-customer-reset', json_encode([$account->public_id, $account->session_version, $requestIds, $addressIds, $notificationIds], JSON_THROW_ON_ERROR)),
        ];
    }
}
