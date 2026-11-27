@extends('layouts.admin')
@section('title', 'درخواست‌های آنلاین تعمیر')
@section('content')
<header class="admin-page-heading">
    <div>
        <h1>درخواست‌های آنلاین تعمیر</h1>
    </div>
    <span class="admin-status">{{ \App\Support\FaNumber::digits($repairs->total()) }} درخواست</span>
</header>

<div class="admin-status-tabs" role="navigation" aria-label="فیلتر وضعیت درخواست‌ها">
    @foreach(['pending' => 'در انتظار پذیرش', 'accepted' => 'پذیرش‌شده', 'all' => 'همه'] as $tabKey => $tabLabel)
        <a href="{{ route('admin.repairs.index', array_filter(['status' => $tabKey !== 'all' ? $tabKey : null, 'q' => $searchQuery !== '' ? $searchQuery : null])) }}"
           class="admin-status-tab"
           @if($status === $tabKey) aria-current="page" @endif>
            <span>{{ $tabLabel }}</span>
            <span class="admin-status-tab__count">{{ \App\Support\FaNumber::digits($counts[$tabKey]) }}</span>
        </a>
    @endforeach
</div>

<section class="admin-panel admin-collection">
    <div class="admin-collection-tools">
        <form method="get" action="{{ route('admin.repairs.index') }}" class="admin-toolbar">
            @if($status !== 'all')
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <div class="admin-toolbar__grow">
                <label class="label" for="repair-requests-q">{{ $canReadCustomer ? 'جست‌وجو (نام مشتری، موبایل یا کد پیگیری)' : 'جست‌وجو با کد پیگیری' }}</label>
                <input class="input" id="repair-requests-q" name="q" type="search" maxlength="100" value="{{ $searchQuery }}" placeholder="{{ $canReadCustomer ? 'مثلاً نام مشتری، ۰۹۱۲... یا کد پیگیری' : 'کد پیگیری' }}">
            </div>
            <button class="btn btn-secondary" type="submit">جست‌وجو</button>
            @if($searchQuery !== '' || $status !== 'all')
                <a class="btn btn-secondary btn-sm" href="{{ route('admin.repairs.index') }}">نمایش همه</a>
            @endif
        </form>
    </div>

    @if($repairs->isEmpty())
        <x-admin.empty-state title="درخواست آنلاینی با این شرایط پیدا نشد" icon="tool">
            {{ $searchQuery !== '' || $status !== 'all' ? 'عبارت جست‌وجو یا تب وضعیت را تغییر دهید.' : 'درخواست‌های تازهٔ وب‌سایت اینجا نمایش داده می‌شوند.' }}
        </x-admin.empty-state>
    @else
        <div class="admin-table-wrap" role="region" aria-label="درخواست‌های آنلاین" tabindex="0">
            <table class="admin-table admin-compact-table repair-list-table admin-clickable-table" role="table">
                <thead role="rowgroup"><tr role="row">
                    <th scope="col" role="columnheader">کد پیگیری</th>
                    @if($canReadCustomer)<th scope="col" role="columnheader">مشتری</th>@endif
                    <th scope="col" role="columnheader">دستگاه و ارسال</th>
                    <th scope="col" role="columnheader">زمان ثبت</th>
                    <th scope="col" role="columnheader">وضعیت</th>
                    <th scope="col" role="columnheader" class="repair-list-action-col"><span class="sr-only">مشاهده</span></th>
                </tr></thead>
                <tbody role="rowgroup">
                    @foreach($repairs as $repair)
                        @php
                            $rawRef = (string) ($repair->tracking_reference ?: $repair->id);
                            $shortRef = preg_match('/\A[0-9]+\z/', $rawRef)
                                ? \App\Support\FaNumber::digits($rawRef)
                                : (strlen($rawRef) > 12 ? substr($rawRef, 0, 10).'…' : $rawRef);
                            $deviceTitle = \App\Support\RepairIdentity::deviceLabel(
                                $repair->device_brand,
                                $canReadCustomer ? $repair->device_model : null
                            );
                            $handover = $canReadCustomer ? $repair->handoverBadge() : null;
                        @endphp
                        <tr role="row" class="admin-clickable-row">
                            <td role="cell" class="repair-list-code">
                                <a class="admin-row-link" href="{{ route('admin.repairs.show', $repair) }}" title="{{ $rawRef }}">
                                    <span class="sr-only">مشاهدهٔ جزئیات درخواست </span>
                                    <bdi dir="ltr" class="repair-code-chip np-latin">{{ $shortRef }}</bdi>
                                </a>
                            </td>
                            @if($canReadCustomer)
                                <td role="cell" class="repair-list-customer">
                                    <span class="admin-mobile-cell-label" aria-hidden="true">مشتری</span>
                                    <strong>{{ $repair->customer_name ?: '—' }}</strong>
                                </td>
                            @endif
                            <td role="cell" class="repair-list-device">
                                <span class="admin-mobile-cell-label" aria-hidden="true">دستگاه</span>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span>{{ \App\Support\LatinText::html($deviceTitle) }}</span>
                                    @if($handover)
                                        <x-admin.status :tone="$handover['tone']">{{ $handover['label'] }}</x-admin.status>
                                    @endif
                                </div>
                            </td>
                            <td role="cell">
                                <span class="admin-mobile-cell-label" aria-hidden="true">زمان ثبت</span>
                                <span><x-finance-time :value="$repair->created_at" /></span>
                            </td>
                            <td role="cell" class="repair-list-status">
                                <x-admin.status :tone="$repair->physical_case_exists ? 'success' : 'warning'">
                                    {{ $repair->physical_case_exists ? 'پذیرش ثبت شده' : 'در انتظار پذیرش' }}
                                </x-admin.status>
                            </td>
                            <td role="cell" class="repair-list-chevron" aria-hidden="true">
                                <x-home.icon name="arrow-left" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($repairs->hasPages())<div class="admin-collection-footer">{{ $repairs->links() }}</div>@endif
    @endif
</section>
@endsection
