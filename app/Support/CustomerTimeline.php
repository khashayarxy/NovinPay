<?php

namespace App\Support;

use App\Enums\RepairStage;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\PaymentAttempt;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CustomerTimeline
{
    public static function repairStatus(RepairCase $repair, ?DeliveryPlan $plan = null): string
    {
        $shipped = $plan?->state === 'dispatched'
            || ($repair->status->closed() && $repair->delivery_method === 'shipping');

        return match (true) {
            $repair->status === RepairStage::Returned => $repair->status->label(),
            $shipped => 'ارسال به شرکت حمل ثبت شد',
            $repair->status === RepairStage::Delivered => 'تحویل ثبت شد',
            default => $repair->status->label(),
        };
    }

    public static function orderStatus(SalesOrder $order, ?DeliveryPlan $plan = null): string
    {
        if ($order->status === 'fulfilled' && $plan?->state === 'dispatched') {
            return 'ارسال به شرکت حمل ثبت شد';
        }
        if ($order->status === 'fulfilled') {
            return 'تحویل ثبت شد';
        }
        if ($order->status === 'cancelled') {
            return SalesOrder::STATUSES['cancelled'];
        }

        $document = $order->id
            ? FinancialDocument::query()->where('sales_order_id', $order->id)->first()
            : null;

        if ($document && $document->charged_minor > 0) {
            if ($document->paid_minor < $document->charged_minor) {
                $latestAttempt = PaymentAttempt::query()
                    ->where('financial_document_id', $document->id)
                    ->latest('id')
                    ->first();

                if ($latestAttempt && $latestAttempt->state === 'failed') {
                    return 'پرداخت ناموفق؛ در انتظار پرداخت';
                }

                return 'در انتظار پرداخت';
            }

            return SalesOrder::STATUSES['confirmed'];
        }

        if ($order->status === 'draft') {
            return 'در انتظار پرداخت';
        }

        return SalesOrder::STATUSES[$order->status];
    }

    public static function request(RepairRequest $request): array
    {
        return [
            ['label' => 'درخواست ثبت شد', 'state' => 'current', 'at' => $request->created_at],
            ['label' => 'دریافت دستگاه', 'state' => 'upcoming', 'at' => null],
        ];
    }

    public static function repair(RepairCase $repair, ?DeliveryPlan $plan = null, bool $public = false): array
    {
        $phases = $repair->simple_workflow ? [
            'received' => 'دستگاه دریافت شد', 'repairing' => 'در حال تعمیر',
            'ready' => 'آماده تحویل', 'delivered' => 'تحویل داده شد',
        ] : [
            'received' => 'دستگاه دریافت شد', 'inspecting' => 'بررسی دستگاه', 'waiting_approval' => 'تأیید هزینه',
            'repairing' => 'در حال تعمیر', 'repaired' => 'تعمیر تکمیل شد', 'ready' => 'آماده تحویل', 'delivered' => 'تحویل داده شد',
        ];
        $map = ['waiting_inspection' => 'received', 'inspected' => 'inspecting', 'approved' => 'waiting_approval'];
        $current = $map[$repair->status->value] ?? $repair->status->value;
        $recorded = ['received' => $public ? null : $repair->received_at];
        foreach ($public ? [] : $repair->events as $event) {
            $key = $map[$event->to_status] ?? $event->to_status;
            if ($key !== null) {
                $recorded[$key] ??= $event->created_at;
            }
        }
        if (! $public && $repair->delivery && $repair->status->closed()) {
            $recorded[$current] = $repair->delivery->delivered_at;
        }
        $cancelled = in_array($repair->status, [RepairStage::ReturnPending, RepairStage::Returned], true);
        if ($cancelled) {
            $phases = array_intersect_key($phases, $recorded);
            $phases[$current] = $repair->status->label();
        }
        if ($repair->status === RepairStage::Delivered && $plan?->state === 'dispatched') {
            $phases[$current] = 'ارسال به شرکت حمل ثبت شد';
        }
        if (! $public && $repair->onlineRequest) {
            $phases = ['requested' => 'درخواست ثبت شد'] + $phases;
            $recorded['requested'] = $repair->onlineRequest->created_at;
        }

        return self::steps($phases, $current, $recorded, $cancelled ? 'cancelled' : ($repair->status->closed() ? 'complete' : 'current'));
    }

    public static function order(SalesOrder $order, ?DeliveryPlan $plan): array
    {
        $document = $order->id ? FinancialDocument::query()->where('sales_order_id', $order->id)->first() : null;
        $labels = ['draft' => 'سفارش ثبت شد'];
        if ($document && $document->charged_minor > 0) {
            $labels['payment'] = 'پرداخت سفارش';
        }
        $labels += [
            'confirmed' => 'سفارش تأیید شد', 'preparing' => 'آماده‌سازی سفارش',
        ];
        if ($plan?->method === 'pickup') {
            $labels['pickup_ready'] = 'آمادهٔ تحویل حضوری';
        }
        $labels['fulfilled'] = $plan && $plan->method !== 'pickup' ? 'ارسال به شرکت حمل' : 'تحویل سفارش';
        $recorded = ['draft' => $order->created_at];
        foreach ($order->events as $event) {
            if ($event->to_status !== null) {
                $recorded[$event->to_status] ??= $event->created_at;
            }
        }
        if ($plan?->id) {
            $progress = DB::table('delivery_events')->where('delivery_plan_id', $plan->id)
                ->whereIn('action', ['order_preparing', 'order_pickup_ready'])->orderBy('id')->get(['action', 'created_at']);
            foreach ($progress as $event) {
                $stage = substr($event->action, 6);
                $recorded[$stage] ??= Carbon::parse($event->created_at);
            }
        }
        $current = $order->status === 'reserved' ? 'draft' : $order->status;
        if (in_array($current, ['confirmed', 'fulfilled'], true)) {
            $recorded['confirmed'] ??= null;
        }
        if ($document && isset($labels['payment']) && $document->paid_minor >= $document->charged_minor) {
            $recorded['payment'] = null;
        } elseif (isset($labels['payment']) && ! in_array($current, ['cancelled', 'fulfilled'], true)) {
            $current = 'payment';
            $labels['payment'] = self::orderStatus($order, $plan);
            unset($recorded['confirmed']);
        }
        if ($current === 'cancelled') {
            $labels = array_intersect_key($labels, $recorded) + ['cancelled' => 'سفارش لغو شد'];
        } elseif ($current === 'fulfilled') {
            $recorded[$current] = $order->fulfilled_at ?? ($recorded[$current] ?? null);
            // The current fulfillment service finalizes the order at dispatch.
            // A dispatch record alone is not evidence of delivery to the buyer.
            if ($plan?->state === 'dispatched') {
                $labels[$current] = 'ارسال به شرکت حمل ثبت شد';
            } else {
                $labels[$current] = 'تحویل ثبت شد';
            }
        } elseif ($current === 'confirmed' && $plan?->state === 'planned') {
            $current = isset($labels['pickup_ready'], $recorded['pickup_ready']) ? 'pickup_ready'
                : (isset($recorded['preparing']) ? 'preparing' : $current);
        }

        return self::steps($labels, $current, $recorded, match ($current) {
            'cancelled' => 'cancelled',
            'fulfilled' => 'complete',
            default => 'current',
        });
    }

    private static function steps(array $labels, string $current, array $recorded, string $currentState): array
    {
        $steps = [];
        $pastCurrent = false;
        foreach ($labels as $key => $label) {
            $steps[] = [
                'label' => $label,
                'at' => $recorded[$key] ?? null,
                'state' => $key === $current ? $currentState : ($pastCurrent ? 'upcoming' : (array_key_exists($key, $recorded) ? 'complete' : 'unrecorded')),
            ];
            $pastCurrent = $pastCurrent || $key === $current;
        }

        return $steps;
    }
}
