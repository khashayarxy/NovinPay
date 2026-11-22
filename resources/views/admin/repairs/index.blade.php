@extends('layouts.admin')
@section('title', 'درخواست‌های آنلاین تعمیر')
@section('content')
<header class="admin-page-heading">
    <div>
        <h1>درخواست‌های آنلاین تعمیر</h1>
        <p>ثبت فرم آنلاین به معنی دریافت فیزیکی دستگاه نیست. برای مشاهده و پذیرش، روی هر ردیف کلیک کنید.</p>
    </div>
    <span class="admin-status">{{ \App\Support\FaNumber::digits($repairs->total()) }} درخواست</span>
</header>
@if($repairs->isEmpty())
    <div class="admin-panel admin-empty">
        <span class="admin-empty-icon"><x-home.icon name="tool" /></span>
        <h2>هنوز درخواست آنلاینی ثبت نشده است</h2>
    </div>
@else
    <section class="admin-panel admin-collection">
        <div class="admin-table-wrap" role="region" aria-label="درخواست‌های آنلاین" tabindex="0">
            <table class="admin-table admin-compact-table repair-list-table admin-clickable-table" role="table">
                <thead role="rowgroup"><tr role="row">
                    <th scope="col" role="columnheader">شمارهٔ داخلی</th>
                    <th scope="col" role="columnheader">برند</th>
                    <th scope="col" role="columnheader">زمان ثبت</th>
                    <th scope="col" role="columnheader">وضعیت</th>
                    <th scope="col" role="columnheader" class="repair-list-action-col"><span class="sr-only">مشاهده</span></th>
                </tr></thead>
                <tbody role="rowgroup">
                    @foreach($repairs as $repair)
                        <tr role="row" class="admin-clickable-row">
                            <td role="cell" class="repair-list-code">
                                <a class="admin-row-link" href="{{ route('admin.repairs.show', $repair) }}">
                                    <span class="sr-only">مشاهدهٔ جزئیات درخواست </span>
                                    <bdi dir="ltr" class="repair-code-chip">#{{ \App\Support\FaNumber::digits($repair->id) }}</bdi>
                                </a>
                            </td>
                            <td role="cell" class="repair-list-customer">
                                <span class="admin-mobile-cell-label" aria-hidden="true">برند</span>
                                <strong><bdi>{{ $repair->device_brand->value }}</bdi></strong>
                            </td>
                            <td role="cell" class="repair-list-device">
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
    </section>
@endif
@endsection
