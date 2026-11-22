@extends('layouts.admin')
@section('title', 'تعمیرات')
@section('content')
<header class="admin-page-heading">
    <div>
        <h1>پرونده‌های تعمیر</h1>
        <p>برای مشاهده یا به‌روزرسانی وضعیت هر پرونده، روی ردیف آن کلیک کنید.</p>
    </div>
    @can('repairs.intake')
        <a class="btn btn-primary" href="{{ route('admin.repair-cases.create') }}">+ پذیرش جدید</a>
    @endcan
</header>
<x-admin.errors />
<section class="admin-panel admin-collection">
    <div class="admin-collection-tools">
        <form method="post" action="{{ route('admin.repair-cases.search') }}" class="admin-toolbar">
            @csrf
            <div class="admin-form-grid">
                <x-operation-field name="q" type="search" label="جست‌وجوی پرونده" :required="false" :maxlength="120" />
                <div>
                    <label class="label" for="repair-filter">وضعیت</label>
                    <select class="input" id="repair-filter" name="status">
                        <option value="">همه وضعیت‌ها</option>
                        @foreach(['received'=>'پذیرش‌شده','repairing'=>'در حال تعمیر','ready'=>'آماده تحویل','delivered'=>'تحویل‌شده','return_pending'=>'تعمیرنشده / لغوشده'] as $value=>$label)
                            <option value="{{ $value }}" @selected(($criteria['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <button class="btn btn-secondary" type="submit">جست‌وجو</button>
        </form>
        @if($criteria)
            <form method="post" action="{{ route('admin.repair-cases.reset') }}">
                @csrf
                <button class="admin-text-link">نمایش همه</button>
            </form>
        @endif
    </div>
    @if($cases->isEmpty())
        <x-admin.empty-state title="پرونده‌ای پیدا نشد" icon="tool">
            {{ $criteria ? 'جست‌وجو یا وضعیت انتخاب‌شده را تغییر دهید.' : 'پرونده‌ها پس از پذیرش دستگاه در این فهرست نمایش داده می‌شوند.' }}
        </x-admin.empty-state>
    @else
        <div class="admin-table-wrap" role="region" aria-label="پرونده‌های تعمیر" tabindex="0">
            <table class="admin-table admin-compact-table repair-list-table admin-clickable-table" role="table">
                <thead role="rowgroup"><tr role="row">
                    <th scope="col" role="columnheader">کد پیگیری</th>
                    <th scope="col" role="columnheader">مشتری</th>
                    <th scope="col" role="columnheader">دستگاه</th>
                    <th scope="col" role="columnheader">وضعیت</th>
                    <th scope="col" role="columnheader" class="repair-list-action-col"><span class="sr-only">مشاهده</span></th>
                </tr></thead>
                <tbody role="rowgroup">
                    @foreach($cases as $case)
                        @php
                            $rawRef = $canReadCustomer ? (string) $case->tracking_reference : (string) $case->case_number;
                            $shortRef = preg_match('/\A[0-9]+\z/', $rawRef)
                                ? \App\Support\FaNumber::digits($rawRef)
                                : (strlen($rawRef) > 12 ? substr($rawRef, 0, 10).'…' : $rawRef);
                        @endphp
                        <tr role="row" class="admin-clickable-row">
                            <td role="cell" class="repair-list-code">
                                <a class="admin-row-link" href="{{ route('admin.repair-cases.show', $case) }}" title="{{ $rawRef }}">
                                    <span class="sr-only">پروندهٔ </span>
                                    <bdi dir="ltr" class="repair-code-chip">{{ $shortRef }}</bdi>
                                </a>
                            </td>
                            <td role="cell" class="repair-list-customer">
                                <span class="admin-mobile-cell-label" aria-hidden="true">مشتری</span>
                                <strong>{{ $canReadCustomer ? $case->customer_name : '—' }}</strong>
                            </td>
                            <td role="cell" class="repair-list-device">
                                <span class="admin-mobile-cell-label" aria-hidden="true">دستگاه</span>
                                <span>{{ $canReadCustomer ? trim(($case->device_brand?->value ? $case->device_brand->value.' · ' : '').$case->device_model) : '—' }}</span>
                            </td>
                            <td role="cell" class="repair-list-status">
                                <x-admin.status :tone="match($case->status->value) { 'delivered', 'ready' => 'success', 'repairing' => 'info', 'return_pending', 'returned' => 'warning', default => 'neutral' }">{{ $case->status->label() }}</x-admin.status>
                            </td>
                            <td role="cell" class="repair-list-chevron" aria-hidden="true">
                                <x-home.icon name="arrow-left" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if($cases->hasPages())<div class="admin-collection-footer">{{ $cases->links() }}</div>@endif
</section>
@endsection
