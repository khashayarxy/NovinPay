<?php

namespace App\Support;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleVersion;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\RepairCase;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;

final class AdminActivityPresenter
{
    /**
     * Batch-resolve actor names and human-readable target labels without N+1 queries.
     *
     * @return array{actors: array<int, string>, targets: array<string, array{label: string, is_html: bool}>}
     */
    public static function meta(iterable $activities): array
    {
        $items = $activities instanceof Paginator
            ? collect($activities->items())
            : collect($activities);
        if ($items->isEmpty()) {
            return ['actors' => [], 'targets' => []];
        }

        $actorIds = $items->pluck('actor_id')->filter()->unique()->values()->all();
        $actors = $actorIds !== []
            ? User::query()->whereIn('id', $actorIds)->pluck('name', 'id')->all()
            : [];

        $idsByType = [];
        foreach ($items as $activity) {
            if ($activity->target_type && $activity->target_id) {
                $idsByType[$activity->target_type][] = (int) $activity->target_id;
            }
        }
        foreach ($idsByType as $type => $ids) {
            $idsByType[$type] = array_values(array_unique($ids));
        }

        $products = ! empty($idsByType['product'])
            ? Product::query()->whereIn('id', $idsByType['product'])->pluck('name', 'id')->all()
            : [];
        $users = ! empty($idsByType['user'])
            ? User::query()->whereIn('id', $idsByType['user'])->pluck('name', 'id')->all()
            : [];
        $articles = ! empty($idsByType['article'])
            ? Article::query()->with('draftVersion:id,article_id,title')->whereIn('id', $idsByType['article'])->get(['id', 'draft_version_id'])->keyBy('id')
            : collect();
        $articleVersions = ! empty($idsByType['article_version'])
            ? ArticleVersion::query()->whereIn('id', $idsByType['article_version'])->pluck('title', 'id')->all()
            : [];
        $articleCategories = ! empty($idsByType['article_category'])
            ? ArticleCategory::query()->whereIn('id', $idsByType['article_category'])->pluck('name', 'id')->all()
            : [];
        $inventoryItems = ! empty($idsByType['inventory_item'])
            ? InventoryItem::query()->whereIn('id', $idsByType['inventory_item'])->pluck('name', 'id')->all()
            : [];
        $repairCases = ! empty($idsByType['repair_case'])
            ? RepairCase::query()->whereIn('id', $idsByType['repair_case'])->pluck('tracking_reference', 'id')->all()
            : [];
        $customers = (! empty($idsByType['customer']) && Gate::allows('customers.contact'))
            ? Customer::query()->whereIn('id', $idsByType['customer'])->get(['id', 'name'])->keyBy('id')
            : collect();

        $targets = [];
        foreach ($items as $activity) {
            $type = (string) ($activity->target_type ?? '');
            $id = $activity->target_id;
            $key = $type.':'.($id ?? 'none');
            if (isset($targets[$key])) {
                continue;
            }

            $targets[$key] = match ($type) {
                'product' => isset($products[$id])
                    ? ['label' => 'محصول «'.LatinText::html($products[$id])->toHtml().'»', 'is_html' => true]
                    : ['label' => 'محصول #'.FaNumber::digits($id), 'is_html' => false],
                'user' => isset($users[$id])
                    ? ['label' => 'حساب '.e($users[$id]), 'is_html' => true]
                    : ['label' => 'حساب #'.FaNumber::digits($id), 'is_html' => false],
                'article' => isset($articles[$id]) && $articles[$id]->draftVersion?->title
                    ? ['label' => 'مقاله «'.e($articles[$id]->draftVersion->title).'»', 'is_html' => true]
                    : ['label' => 'مقاله #'.FaNumber::digits($id), 'is_html' => false],
                'article_version' => isset($articleVersions[$id])
                    ? ['label' => 'نسخهٔ مقاله «'.e($articleVersions[$id]).'»', 'is_html' => true]
                    : ['label' => 'نسخهٔ مقاله #'.FaNumber::digits($id), 'is_html' => false],
                'article_category' => isset($articleCategories[$id])
                    ? ['label' => 'دستهٔ مقاله «'.e($articleCategories[$id]).'»', 'is_html' => true]
                    : ['label' => 'دستهٔ مقاله #'.FaNumber::digits($id), 'is_html' => false],
                'inventory_item' => isset($inventoryItems[$id])
                    ? ['label' => 'کالای انبار «'.LatinText::html($inventoryItems[$id])->toHtml().'»', 'is_html' => true]
                    : ['label' => 'کالای انبار #'.FaNumber::digits($id), 'is_html' => false],
                'sales_order' => ['label' => 'سفارش #'.FaNumber::digits($id), 'is_html' => false],
                'repair_case' => isset($repairCases[$id])
                    ? ['label' => 'پروندهٔ تعمیر '.(preg_match('/\A[0-9]+\z/', (string) $repairCases[$id]) ? FaNumber::digits($repairCases[$id]) : e(substr((string) $repairCases[$id], 0, 12))), 'is_html' => true]
                    : ['label' => 'پروندهٔ تعمیر #'.FaNumber::digits($id), 'is_html' => false],
                'repair_request' => ['label' => 'درخواست آنلاین #'.FaNumber::digits($id), 'is_html' => false],
                'customer' => isset($customers[$id])
                    ? ['label' => 'مشتری '.e($customers[$id]->name), 'is_html' => true]
                    : ['label' => 'پروفایل مشتری #'.FaNumber::digits($id), 'is_html' => false],
                'consultation' => ['label' => 'مشاوره #'.FaNumber::digits($id), 'is_html' => false],
                'financial_entry', 'financial_document', 'payment_attempt', 'payment_receipt', 'payment_transaction' => [
                    'label' => match ($type) {
                        'financial_document' => 'سند مالی #'.FaNumber::digits($id),
                        'payment_attempt' => 'تلاش پرداخت #'.FaNumber::digits($id),
                        'payment_receipt' => 'رسید پرداخت #'.FaNumber::digits($id),
                        default => 'تراکنش مالی #'.FaNumber::digits($id),
                    },
                    'is_html' => false,
                ],
                'public_website' => ['label' => 'وب‌سایت عمومی', 'is_html' => false],
                default => ['label' => $id ? 'رکورد #'.FaNumber::digits($id) : 'سامانه', 'is_html' => false],
            };
        }

        return ['actors' => $actors, 'targets' => $targets];
    }
}
