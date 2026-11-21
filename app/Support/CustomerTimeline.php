<?php

namespace App\Support;

use App\Enums\RepairStage;
use App\Models\DeliveryPlan;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;

final class CustomerTimeline
{
    public static function repairStatus(RepairCase $repair, ?DeliveryPlan $plan = null): string
    {
        $shipped = $plan?->state === 'dispatched'
            || ($repair->status->closed() && $repair->delivery_method === 'shipping');

        return $shipped ? 'ارسال به شرکت حمل ثبت شد' : ($repair->status === RepairStage::Delivered ? 'تحویل ثبت شد' : $repair->status->label());
    }

    public static function orderStatus(SalesOrder $order, ?DeliveryPlan $plan = null): string
    {
        if ($order->status === 'fulfilled' && $plan?->state === 'dispatched') {
            return 'ارسال به شرکت حمل ثبت شد';
        }

        return $order->status === 'fulfilled' ? 'تحویل ثبت شد' : SalesOrder::STATUSES[$order->status];
    }

    public static function request(RepairRequest $request): array
    {
        return [
            ['label' => 'درخواست ثبت شد', 'state' => 'current', 'at' => $request->created_at],
            ['label' => 'دریافت دستگاه', 'state' => 'upcoming', 'at' => null],
        ];
    }

    public static function repair(RepairCase $repair, ?DeliveryPlan $plan = null): array
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
        $recorded = ['received' => $repair->received_at];
        foreach ($repair->events as $event) {
            $key = $map[$event->to_status] ?? $event->to_status;
            if ($key !== null) {
                $recorded[$key] ??= $event->created_at;
            }
        }
        if ($repair->delivery && $repair->status->closed()) {
            $recorded[$current] = $repair->delivery->delivered_at;
        }
        if (in_array($repair->status, [RepairStage::ReturnPending, RepairStage::Returned], true)) {
            $phases = array_intersect_key($phases, $recorded);
            $phases[$current] = $repair->status->label();
        }
        if ($repair->status->closed() && $plan?->state === 'dispatched') {
            $phases[$current] = 'ارسال به شرکت حمل ثبت شد';
        }
        if ($repair->onlineRequest) {
            $phases = ['requested' => 'درخواست ثبت شد'] + $phases;
            $recorded['requested'] = $repair->onlineRequest->created_at;
        }

        return self::steps($phases, $current, $recorded);
    }

    public static function order(SalesOrder $order, ?DeliveryPlan $plan): array
    {
        $labels = [
            'draft' => 'سفارش ثبت شد', 'reserved' => 'موجودی رزرو شد',
            'confirmed' => 'سفارش تأیید شد', 'fulfilled' => 'تحویل داده شد',
        ];
        $recorded = ['draft' => $order->created_at];
        foreach ($order->events as $event) {
            if ($event->to_status !== null) {
                $recorded[$event->to_status] ??= $event->created_at;
            }
        }
        $current = $order->status;
        if ($current === 'cancelled') {
            $labels = array_intersect_key($labels, $recorded) + ['cancelled' => 'سفارش لغو شد'];
        } elseif ($current === 'fulfilled') {
            $recorded[$current] = $order->fulfilled_at ?? ($recorded[$current] ?? null);
            // The current fulfillment service finalizes the order at dispatch.
            // A dispatch record alone is not evidence of delivery to the buyer.
            if ($plan?->state === 'dispatched') {
                $labels[$current] = 'ارسال به شرکت حمل ثبت شد';
            }
        }

        return self::steps($labels, $current, $recorded);
    }

    private static function steps(array $labels, string $current, array $recorded): array
    {
        $steps = [];
        $pastCurrent = false;
        foreach ($labels as $key => $label) {
            $steps[] = [
                'label' => $label,
                'at' => $recorded[$key] ?? null,
                'state' => $key === $current ? 'current' : (isset($recorded[$key]) ? 'complete' : ($pastCurrent ? 'upcoming' : 'unrecorded')),
            ];
            $pastCurrent = $pastCurrent || $key === $current;
        }

        return $steps;
    }
}
