<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CommerceRequest;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\CommerceManagement;
use App\Support\CommerceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'condition' => ['nullable', Rule::in(['new', 'used'])], 'low' => ['nullable', Rule::in(['1'])], 'search' => ['nullable', Rule::in(['sku', 'serial', 'name'])]]);
        $query = InventoryItem::query();
        if (! empty($filters['q'])) {
            $term = $filters['q'];
            $pattern = strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            match ($filters['search'] ?? 'sku') {
                'serial' => $query->whereIn('id', InventoryUnit::query()->select('inventory_item_id')->where('serial', strtoupper($term))),
                'name' => $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]),
                default => $query->where('sku', strtoupper($term)),
            };
        }
        if (! empty($filters['condition'])) {
            $query->where('condition', $filters['condition']);
        }
        if (! empty($filters['low'])) {
            $query->where('is_low_stock', true);
        }

        return view('admin.inventory.index', ['items' => $query->latest('id')->paginate(20)->appends($filters), 'filters' => $filters]);
    }

    public function create()
    {
        CommerceAccess::authorize(auth()->user(), 'item.create');

        return view('admin.inventory.create', ['products' => Gate::allows('products.view') ? Product::query()->orderBy('id')->limit(100)->get(['id', 'name']) : collect()]);
    }

    public function store(CommerceRequest $request, CommerceManagement $management)
    {
        $id = $management->execute($request->user(), 'item.create', $request->validated());

        return redirect()->route('admin.inventory.show', InventoryItem::findOrFail($id))->with('success', 'کالا بدون موجودی اولیه تعریف شد؛ ورود کالا را جداگانه ثبت کنید.');
    }

    public function show(InventoryItem $item)
    {
        return view('admin.inventory.show', ['item' => $item, 'units' => $item->units()->latest('id')->paginate(20, ['id', 'serial', 'state'], 'units_page'), 'movements' => $item->movements()->latest('id')->paginate(15, ['*'], 'movements_page')]);
    }

    public function update(CommerceRequest $request, InventoryItem $item, CommerceManagement $management)
    {
        $management->execute($request->user(), $request->operation(), $request->validated(), $item);

        return redirect()->route('admin.inventory.show', $item)->with('success', 'عملیات موجودی ثبت شد.');
    }

    public function movements(Request $request)
    {
        $filters = $request->validate(['kind' => ['nullable', Rule::in(['receive', 'adjust', 'reserve', 'release', 'dispatch', 'configure'])]]);
        $query = StockMovement::query();
        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        $movements = $query->latest('id')->paginate(25)->appends($filters);
        $items = InventoryItem::query()->whereIn('id', $movements->pluck('inventory_item_id'))->get(['id', 'public_id', 'sku', 'name'])->keyBy('id');

        return view('admin.inventory.movements', compact('movements', 'items', 'filters'));
    }
}
