<?php

namespace App\Services;

use App\Enums\ProductCategory;
use App\Enums\RepairBrand;
use App\Models\CustomerAccount;
use App\Models\CustomerDevice;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CustomerDeviceRegistry
{
    /**
     * Record or update a single device for a customer account.
     */
    public function record(
        CustomerAccount $account,
        string $brand,
        string $model,
        ?string $serial = null,
        string $sourceType = 'repair',
        ?string $sourceRef = null,
        mixed $usedAt = null,
    ): ?CustomerDevice {
        if (! Schema::hasTable('customer_devices')) {
            return null;
        }

        $normalizedBrand = $this->normalizeBrand($brand);
        $cleanModel = trim(preg_replace('/\s+/u', ' ', $model) ?? '');
        if ($cleanModel === '') {
            return null;
        }
        $cleanSerial = $serial !== null ? trim($serial) : null;
        if ($cleanSerial === '') {
            $cleanSerial = null;
        }

        $fingerprint = $this->fingerprint($normalizedBrand, $cleanModel, $cleanSerial);
        $timestamp = $usedAt ?? now();

        $existing = CustomerDevice::query()
            ->where('customer_account_id', $account->id)
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($existing) {
            if ($cleanSerial !== null && empty($existing->device_serial)) {
                $existing->device_serial = $cleanSerial;
            }
            if (! $existing->last_used_at || $timestamp > $existing->last_used_at) {
                $existing->last_used_at = $timestamp;
            }
            if ($sourceRef !== null) {
                $existing->source_ref = $sourceRef;
            }
            $existing->save();

            return $existing;
        }

        // If a serial is newly provided for an existing brand+model without serial, upgrade that record
        if ($cleanSerial !== null) {
            $noSerialFp = $this->fingerprint($normalizedBrand, $cleanModel, null);
            $withoutSerial = CustomerDevice::query()
                ->where('customer_account_id', $account->id)
                ->where('fingerprint', $noSerialFp)
                ->first();
            if ($withoutSerial) {
                $withoutSerial->forceFill([
                    'device_serial' => $cleanSerial,
                    'fingerprint' => $fingerprint,
                    'source_type' => $sourceType,
                    'source_ref' => $sourceRef ?? $withoutSerial->source_ref,
                    'last_used_at' => $timestamp,
                ])->save();

                return $withoutSerial;
            }
        }

        $device = new CustomerDevice;
        $device->forceFill([
            'public_id' => (string) Str::uuid(),
            'customer_account_id' => $account->id,
            'device_brand' => $normalizedBrand,
            'device_model' => mb_substr($cleanModel, 0, 120),
            'device_serial' => $cleanSerial !== null ? mb_substr($cleanSerial, 0, 120) : null,
            'fingerprint' => $fingerprint,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef !== null ? mb_substr($sourceRef, 0, 64) : null,
            'last_used_at' => $timestamp,
        ])->save();

        return $device;
    }

    /**
     * Backfill & return up to $limit devices from past repairs, repair requests, and paid POS orders.
     *
     * @return Collection<int, CustomerDevice>
     */
    public function forAccount(CustomerAccount $account, int $limit = 8): Collection
    {
        if (! Schema::hasTable('customer_devices')) {
            return collect();
        }

        $ownedScope = fn ($query) => $query->where('customer_account_id', $account->id)
            ->when($account->customer_id, fn ($q) => $q->orWhere(fn ($legacy) => $legacy
                ->whereNull('customer_account_id')->where('customer_id', $account->customer_id)));

        $cases = RepairCase::query()
            ->where($ownedScope)
            ->latest('id')
            ->limit(15)
            ->get(['device_brand', 'device_model', 'device_serial', 'tracking_reference', 'received_at', 'created_at']);

        foreach ($cases as $case) {
            $this->record(
                $account,
                $case->device_brand?->value ?? (string) $case->getRawOriginal('device_brand'),
                (string) $case->device_model,
                $case->device_serial ? (string) $case->device_serial : null,
                'repair',
                (string) $case->tracking_reference,
                $case->received_at ?? $case->created_at,
            );
        }

        $requests = RepairRequest::query()
            ->where($ownedScope)
            ->latest('id')
            ->limit(15)
            ->get();

        foreach ($requests as $req) {
            $parsed = $req->parsedIssue();
            $this->record(
                $account,
                $req->device_brand?->value ?? (string) $req->getRawOriginal('device_brand'),
                (string) $req->device_model,
                ! empty($parsed['serial']) ? (string) $parsed['serial'] : null,
                'repair',
                (string) $req->tracking_reference,
                $req->created_at,
            );
        }

        $paidOrders = SalesOrder::query()
            ->where($ownedScope)
            ->whereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('financial_documents')
                    ->whereColumn('financial_documents.sales_order_id', 'sales_orders.id')
                    ->whereColumn('financial_documents.paid_minor', '>=', 'financial_documents.charged_minor')
                    ->where('financial_documents.charged_minor', '>', 0);
            })
            ->with(['lines.inventoryItem.product'])
            ->latest('id')
            ->limit(10)
            ->get();

        foreach ($paidOrders as $order) {
            $orderCode = strtoupper(substr((string) $order->public_id, 0, 8));
            foreach ($order->lines as $line) {
                $product = $line->inventoryItem?->product;
                if ($product && $product->category !== ProductCategory::PosTerminal) {
                    continue;
                }
                $brand = (string) ($product?->brand ?: $this->inferBrandFromText((string) $line->description));
                $model = (string) ($product?->model ?: $product?->name ?: $line->description);
                $this->record(
                    $account,
                    $brand,
                    $model,
                    null,
                    'order',
                    $orderCode,
                    $order->created_at,
                );
            }
        }

        return CustomerDevice::query()
            ->where('customer_account_id', $account->id)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function findOwned(CustomerAccount $account, ?string $publicId): ?CustomerDevice
    {
        if (! is_string($publicId) || ! Str::isUuid($publicId) || ! Schema::hasTable('customer_devices')) {
            return null;
        }

        return CustomerDevice::query()
            ->where('customer_account_id', $account->id)
            ->where('public_id', $publicId)
            ->first();
    }

    public function normalizeBrand(string $brand): string
    {
        $trimmed = trim($brand);
        foreach (RepairBrand::cases() as $case) {
            if (strcasecmp($case->value, $trimmed) === 0) {
                return $case->value;
            }
        }

        return $this->inferBrandFromText($trimmed);
    }

    private function inferBrandFromText(string $text): string
    {
        $upper = strtoupper($text);

        return match (true) {
            str_contains($upper, 'PAX') || str_contains($text, 'پکس') => RepairBrand::Pax->value,
            str_contains($upper, 'NEWLAND') || str_contains($text, 'نیولند') => RepairBrand::Newland->value,
            str_contains($upper, 'NEWPOS') || str_contains($text, 'نیوپوز') => RepairBrand::NewPos->value,
            str_contains($upper, 'TOPWISE') || str_contains($text, 'تاپ‌وایز') => RepairBrand::Topwise->value,
            str_contains($upper, 'VERIFONE') || str_contains($text, 'وریفون') => RepairBrand::Verifone->value,
            str_contains($upper, 'INGENICO') || str_contains($text, 'اینجنیکو') => RepairBrand::Ingenico->value,
            str_contains($upper, 'MOREFUN') || str_contains($text, 'مورفان') => RepairBrand::MoreFun->value,
            str_contains($upper, 'SZZT') => RepairBrand::Szzt->value,
            default => RepairBrand::Other->value,
        };
    }

    private function fingerprint(string $brand, string $model, ?string $serial): string
    {
        $canonical = mb_strtolower(trim($brand)).'|'.mb_strtolower(trim($model)).'|'.mb_strtolower(trim((string) $serial));

        return hash_hmac('sha256', 'customer-device:'.$canonical, (string) config('app.key'));
    }
}
