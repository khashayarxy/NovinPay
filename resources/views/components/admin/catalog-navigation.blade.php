@can('products.view')
    @can('inventory.view')
        <nav class="admin-view-switch" aria-label="نمای محصولات و موجودی">
            <a href="{{ route('admin.products.index') }}" @if(request()->routeIs('admin.products.*')) aria-current="page" @endif>محصولات</a>
            <a href="{{ route('admin.inventory.index') }}" @if(request()->routeIs('admin.inventory.*')) aria-current="page" @endif>موجودی فیزیکی</a>
        </nav>
    @endcan
@endcan
