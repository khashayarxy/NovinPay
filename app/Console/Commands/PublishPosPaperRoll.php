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
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PublishPosPaperRoll extends Command
{
    public const SLUG = 'pos-paper-roll';

    public const SKU = 'POS-PAPER-ROLL';

    private const CREATE_OPERATION = '4fb8f326-63c9-41ad-a3a1-66d6e39de544';

    private const RECEIVE_OPERATION = '50687872-9916-452a-bf8d-1ad5727eeec1';

    protected $signature = 'novinpay:publish-pos-paper-roll {--apply : Publish this product and receive its initial stock}';

    protected $description = 'Publish the POS paper roll and receive the initial stock once through the inventory ledger';

    public function handle(CommerceManagement $commerce): int
    {
        if (! $this->option('apply')) {
            $this->error('Use --apply to authorize the catalog and inventory update.');

            return self::INVALID;
        }

        $result = DB::transaction(function () use ($commerce): string {
            $owner = AdminAccount::query()->where('owner_slot', 1)->lockForUpdate()->first();
            if (! $owner?->is_active || ! $owner->isSuperAdmin() || ! $owner->user) {
                return 'ACTIVE_OWNER_REQUIRED';
            }

            $product = Product::query()->where('slug', self::SLUG)->first();
            $item = InventoryItem::query()->where('sku', self::SKU)->first();
            $received = DB::table('commerce_operations as operation')
                ->join('stock_movements as movement', 'movement.commerce_operation_id', '=', 'operation.id')
                ->where('operation.operation_key', self::RECEIVE_OPERATION)
                ->where('operation.action', 'item.receive')
                ->where('movement.kind', 'receive')
                ->where('movement.on_hand_delta', 100)
                ->value('movement.inventory_item_id');

            if ($received !== null && $product && $item
                && (int) $item->product_id === $product->id && (int) $received === $item->id) {
                return 'POS_PAPER_ROLL_ALREADY_PUBLISHED';
            }
            if ($product || $item || $received !== null) {
                return 'POS_PAPER_ROLL_IDENTITY_CONFLICT';
            }

            $name = 'رول کاغذ کارتخوان';
            $product = Product::create([
                'name' => $name,
                'slug' => self::SLUG,
                'summary' => 'رول کاغذ برای چاپ رسید کارتخوان',
                'description' => 'رول کاغذ مصرفی برای چاپ رسید دستگاه کارتخوان. پیش از خرید، اندازهٔ رول موردنیاز دستگاه خود را بررسی کنید.',
                'category' => ProductCategory::Consumable,
                'condition' => ProductCondition::New,
                'price' => 85_000,
                'currency' => 'TOMAN',
                'stock_status' => StockStatus::InStock,
                'is_sample' => false,
                'is_published' => true,
                'online_purchasing_enabled' => true,
            ]);
            AdminHistory::record(AdminAction::ProductCreated, 'product', $product->id, $owner->user_id);
            AdminHistory::record(AdminAction::ProductPriceChanged, 'product', $product->id, $owner->user_id);
            $item = InventoryItem::query()->findOrFail($commerce->execute($owner->user, 'item.create', [
                'operation_key' => self::CREATE_OPERATION,
                'sku' => self::SKU,
                'name' => $name,
                'condition' => ProductCondition::New->value,
                'serialized' => false,
                'product_id' => $product->id,
                'low_stock_threshold' => 0,
                'reason' => 'تعریف موجودی رول کاغذ کارتخوان',
            ]));
            $commerce->execute($owner->user, 'item.receive', [
                'operation_key' => self::RECEIVE_OPERATION,
                'version' => $item->version,
                'quantity' => 100,
                'reason' => 'ثبت موجودی اولیه رول کاغذ کارتخوان',
            ], $item);

            return 'POS_PAPER_ROLL_PUBLISHED';
        }, 3);

        if (in_array($result, ['POS_PAPER_ROLL_PUBLISHED', 'POS_PAPER_ROLL_ALREADY_PUBLISHED'], true)) {
            $this->info($result);

            return self::SUCCESS;
        }

        $this->error($result);

        return self::FAILURE;
    }
}
