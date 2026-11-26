<?php

namespace App\Support;

use App\Models\CustomerAccount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

final class PaymentTestProduct
{
    public static function slug(): string
    {
        return trim((string) config('payments.test_product.slug', 'zibal-gateway-test-5000'));
    }

    public static function normalizePhone(mixed $value): string
    {
        $phone = RepairIdentity::phone($value);
        if ($phone === '') {
            return '';
        }

        $phone = preg_replace('/^98(?=9\d{9}\z)/', '0', $phone) ?? '';

        return preg_match('/\A09[0-9]{9}\z/', $phone) === 1 ? $phone : '';
    }

    /**
     * @return list<string>
     */
    public static function allowedPhones(): array
    {
        $raw = config('payments.test_product.allowed_phones', []);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $entry) {
            $phone = self::normalizePhone($entry);
            if ($phone !== '') {
                $normalized[] = $phone;
            }
        }

        return array_values(array_unique($normalized));
    }

    public static function isTestProduct(Product|string|null $product): bool
    {
        $configuredSlug = self::slug();
        if ($configuredSlug === '') {
            return false;
        }

        $productSlug = $product instanceof Product ? (string) $product->slug : trim((string) $product);

        return $productSlug !== '' && $productSlug === $configuredSlug;
    }

    public static function canAccess(?CustomerAccount $account): bool
    {
        if (! $account || ! $account->is_active || ! $account->verified_at) {
            return false;
        }

        $phone = self::normalizePhone($account->phone);

        return $phone !== '' && in_array($phone, self::allowedPhones(), true);
    }

    public static function canSeeProduct(Product|string|null $product, ?CustomerAccount $account): bool
    {
        if (! self::isTestProduct($product)) {
            return true;
        }

        if (self::canAccess($account)) {
            return true;
        }

        $adminUser = Auth::guard('web')->user();

        return $adminUser instanceof User && AdminAccess::account($adminUser) !== null;
    }
}
