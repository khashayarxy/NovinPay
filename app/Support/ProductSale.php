<?php

namespace App\Support;

use App\Enums\StockStatus;
use App\Models\Product;
use InvalidArgumentException;

enum ProductSale
{
    case Online;
    case AwaitingStock;
    case Offline;
    case PriceInquiry;
    case PriceInquiryAvailable;
    case OutOfStock;
    case Paused;
    case Unpublished;

    public static function priceConfirmed(Product $product): bool
    {
        return ! $product->is_sample && $product->currency === 'TOMAN'
            && $product->price > 0 && $product->price <= OrderMoney::MAX_UNIT_MAJOR;
    }

    public static function permitsInventoryCheck(Product $product): bool
    {
        return $product->is_published && $product->online_purchasing_enabled && ! $product->salePaused()
            && self::priceConfirmed($product) && $product->stock_status->permitsCheckout();
    }

    public static function resolve(Product $product, ?bool $available): self
    {
        return match (true) {
            ! $product->is_published => self::Unpublished,
            $product->salePaused() => self::Paused,
            $product->stock_status === StockStatus::OutOfStock, $available === false => self::OutOfStock,
            ! self::priceConfirmed($product) => $available ? self::PriceInquiryAvailable : self::PriceInquiry,
            $available === true && self::permitsInventoryCheck($product) => self::Online,
            $available === true && $product->stock_status->permitsCheckout() => self::Offline,
            default => self::AwaitingStock,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Online => 'افزودن به سبد خرید',
            self::AwaitingStock => 'وضعیت موجودی هنوز مشخص نیست',
            self::Offline => 'هماهنگی خرید',
            self::OutOfStock => 'ناموجود',
            self::Paused => 'فروش موقتاً متوقف است',
            self::PriceInquiry, self::PriceInquiryAvailable => 'استعلام قیمت',
            self::Unpublished => throw new InvalidArgumentException('Unpublished products have no public action.'),
        };
    }

    public function contactAction(): bool
    {
        return in_array($this, [self::Offline, self::PriceInquiry, self::PriceInquiryAvailable], true);
    }

    public function stockLabel(Product $product): string
    {
        return match ($this) {
            self::Online, self::Offline, self::PriceInquiryAvailable => $product->stock_status->label(),
            self::OutOfStock => 'ناموجود',
            self::Paused => $this->label(),
            default => 'موجودی تأیید نشده',
        };
    }
}
