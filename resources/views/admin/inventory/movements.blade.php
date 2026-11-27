@extends('layouts.admin')
@section('title', 'گردش موجودی')
@section('content')
<header class="admin-page-heading">
    <div>
        <span class="admin-eyebrow">انبار فیزیکی</span>
        <h1>گردش موجودی</h1>
        <p>ثبت‌های ورود، اصلاح، رزرو، آزادسازی و تحویل کالاها.</p>
    </div>
    <a class="btn btn-secondary" href="{{ route('admin.inventory.index') }}">بازگشت به موجودی</a>
</header>
<x-admin.errors/>
<section class="admin-panel admin-collection">
    <div class="admin-collection-tools">
        <form class="commerce-filters" method="get">
            <div>
                <label class="label" for="movement-kind">نوع گردش</label>
                <select class="input" name="kind" id="movement-kind">
                    <option value="">همه انواع گردش</option>
                    @foreach(['configure'=>'تعریف / تنظیم','receive'=>'ورود','adjust'=>'اصلاح','reserve'=>'رزرو','release'=>'آزادسازی','dispatch'=>'تحویل'] as $value=>$label)
                        <option value="{{ $value }}" @selected(($filters['kind'] ?? '')===$value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-secondary" type="submit">اعمال فیلتر</button>
            @if(!empty($filters['kind']))
                <a class="btn btn-secondary btn-sm" href="{{ route('admin.inventory.movements') }}">پاک‌کردن</a>
            @endif
        </form>
    </div>
    <div class="p-4">
        <x-stock-movements :movements="$movements" :items="$items"/>
    </div>
    @if($movements->hasPages())<div class="admin-collection-footer">{{ $movements->links() }}</div>@endif
</section>
@endsection
