@props(['id' => 'admin-filters', 'activeCount' => 0, 'label' => 'فیلترها'])
<div class="admin-filter-drawer">
    <button type="button" class="admin-filter-drawer__toggle" data-toggle-panel="{{ $id }}" aria-expanded="{{ $activeCount > 0 ? 'true' : 'false' }}" aria-controls="{{ $id }}">
        <span class="inline-flex items-center gap-2">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16l-6 7.5V19l-4-2v-5.5z"/></svg>
            <span>{{ $label }}</span>
        </span>
        @if($activeCount > 0)
            <span class="admin-filter-drawer__badge">{{ \App\Support\FaNumber::digits($activeCount) }}</span>
        @endif
    </button>
    <div id="{{ $id }}" class="admin-filter-drawer__body" @if($activeCount === 0) hidden @endif>
        {{ $slot }}
    </div>
</div>
