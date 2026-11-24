<?php

namespace App\Console\Commands;

use App\Enums\AdminAction;
use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Models\AdminAccount;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Services\CommerceManagement;
use App\Support\AdminHistory;
use App\Support\PaymentTestProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProvisionPaymentTestProduct extends Command
{
    public const SKU = 'ZIBAL-GATEWAY-TEST-5000';

    public const PRICE_TOMAN = 5_000;

    public const TARGET_AVAILABLE_STOCK = 999;

    public const MIN_AVAILABLE_STOCK = 100;

    private const CREATE_OPERATION = '9d62b2c5-58af-4b20-90b7-14e94b8f5000';

    private const INITIAL_RECEIVE_OPERATION = '402a628e-1a55-43f3-891c-c448bc985000';

    protected $signature = 'novinpay:provision-payment-test-product {--apply : Provision or refresh the private 5,000 Toman payment-gateway test product and its stock}';

    protected $description = 'Ensure the private 5,000 Toman payment-gateway test product exists with high stock through the inventory ledger';

    public function handle(CommerceManagement $commerce): int
    {
        if (! $this->option('apply')) {
            $this->error('Use --apply to authorize provisioning the private payment test product.');

            return self::INVALID;
        }

        $slug = PaymentTestProduct::slug();
        if ($slug === '' || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
            $this->error('INVALID_TEST_PRODUCT_SLUG');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($commerce, $slug): string {
            $owner = AdminAccount::query()->where('owner_slot', 1)->lockForUpdate()->first();
            if (! $owner?->is_active || ! $owner->isSuperAdmin() || ! $owner->user) {
                return 'ACTIVE_OWNER_REQUIRED';
            }

            $name = 'محصول تست درگاه پرداخت (خصوصی)';
            $summary = 'محصول خصوصی ویژهٔ تست تراکنش ۵٬۰۰۰ تومانی درگاه پرداخت زیبال';
            $description = 'این محصول صرفاً برای تست داخلی اتصال و پرداخت درگاه زیبال با مبلغ ۵٬۰۰۰ تومان تعریف شده و فقط برای شماره‌های مجاز قابل مشاهده و خرید است. برای پرداخت دقیقاً ۵٬۰۰۰ تومان بدون هزینه ارسال، روش «تحویل حضوری» را انتخاب کنید.';

            $product = Product::query()->where('slug', $slug)->lockForUpdate()->first();
            $created = false;

            if (! $product) {
                $product = Product::create([
                    'name' => $name,
                    'slug' => $slug,
                    'summary' => $summary,
                    'description' => $description,
                    'category' => ProductCategory::Peripheral,
                    'condition' => ProductCondition::New,
                    'price' => self::PRICE_TOMAN,
                    'currency' => 'TOMAN',
                    'stock_status' => StockStatus::InStock,
                    'is_sample' => false,
                    'is_published' => true,
                    'online_purchasing_enabled' => true,
                ]);
                AdminHistory::record(AdminAction::ProductCreated, 'product', $product->id, $owner->user_id);
                AdminHistory::record(AdminAction::ProductPriceChanged, 'product', $product->id, $owner->user_id);
                $created = true;
            } else {
                $priceChanged = (int) $product->price !== self::PRICE_TOMAN;
                $product->forceFill([
                    'name' => $name,
                    'summary' => $summary,
                    'description' => $description,
                    'category' => ProductCategory::Peripheral,
                    'condition' => ProductCondition::New,
                    'price' => self::PRICE_TOMAN,
                    'currency' => 'TOMAN',
                    'stock_status' => StockStatus::InStock,
                    'is_sample' => false,
                    'is_published' => true,
                    'online_purchasing_enabled' => true,
                ]);
                if ($product->isDirty()) {
                    $product->save();
                }
                if ($priceChanged) {
                    AdminHistory::record(AdminAction::ProductPriceChanged, 'product', $product->id, $owner->user_id);
                }
            }

            $item = InventoryItem::query()->where('sku', self::SKU)->lockForUpdate()->first();
            if ($item && (int) $item->product_id !== (int) $product->id) {
                return 'PAYMENT_TEST_PRODUCT_IDENTITY_CONFLICT';
            }

            if (! $item) {
                $item = InventoryItem::query()->findOrFail($commerce->execute($owner->user, 'item.create', [
                    'operation_key' => self::CREATE_OPERATION,
                    'sku' => self::SKU,
                    'name' => $name,
                    'condition' => ProductCondition::New->value,
                    'serialized' => false,
                    'product_id' => $product->id,
                    'low_stock_threshold' => 0,
                    'reason' => 'تعریف موجودی محصول تست خصوصی درگاه پرداخت',
                ]));
                $item = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            }

            $available = (int) $item->available;
            if ((int) $item->on_hand === 0) {
                $commerce->execute($owner->user, 'item.receive', [
                    'operation_key' => self::INITIAL_RECEIVE_OPERATION,
                    'version' => $item->version,
                    'quantity' => self::TARGET_AVAILABLE_STOCK,
                    'reason' => 'ثبت موجودی اولیه محصول تست خصوصی درگاه پرداخت',
                ], $item);
            } elseif ($available < self::MIN_AVAILABLE_STOCK) {
                $delta = self::TARGET_AVAILABLE_STOCK - $available;
                if ($delta > 0) {
                    $commerce->execute($owner->user, 'item.receive', [
                        'operation_key' => (string) Str::uuid(),
                        'version' => $item->version,
                        'quantity' => $delta,
                        'reason' => 'تکمیل موجودی محصول تست خصوصی درگاه پرداخت',
                    ], $item);
                }
            }

            return $created ? 'PAYMENT_TEST_PRODUCT_PROVISIONED' : 'PAYMENT_TEST_PRODUCT_READY';
        }, 3);

        if (in_array($result, ['PAYMENT_TEST_PRODUCT_PROVISIONED', 'PAYMENT_TEST_PRODUCT_READY'], true)) {
            $this->info($result);

            return self::SUCCESS;
        }

        $this->error($result);

        return self::FAILURE;
    }
}
