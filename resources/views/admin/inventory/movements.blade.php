@extends('layouts.admin')
@section('title', 'گردش موجودی')
@section('content')
<header class="admin-page-heading"><div><p class="admin-eyebrow">دفتر گردش انبار</p><h1>گردش موجودی</h1><p>ورود، اصلاح، رزرو، آزادسازی و تحویل به تفکیک ثبت می‌شوند.</p></div><a class="admin-text-link" href="{{ route('admin.inventory.index') }}">بازگشت به موجودی ←</a></header>
<x-admin.errors/>
<section class="admin-panel"><form class="commerce-filters" method="get"><div><label class="label" for="movement-kind">نوع گردش</label><select class="input" name="kind" id="movement-kind"><option value="">همه</option>@foreach(['configure'=>'تعریف / تنظیم','receive'=>'ورود','adjust'=>'اصلاح','reserve'=>'رزرو','release'=>'آزادسازی','dispatch'=>'تحویل'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['kind'] ?? '')===$value)>{{ $label }}</option>@endforeach</select></div><button class="btn btn-primary">اعمال فیلتر</button></form><x-stock-movements :movements="$movements" :items="$items"/>{{ $movements->links() }}</section>
@endsection
