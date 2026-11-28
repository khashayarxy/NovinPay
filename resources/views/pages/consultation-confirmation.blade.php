@extends('layouts.app')
@section('title', 'درخواست مشاوره ثبت شد')
@section('meta_description', 'درخواست مشاورهٔ شما در نوین‌پی ثبت شد.')
@section('content')
    <div class="np-container public-section">
        <x-status-confirmation title="درخواست مشاوره ثبت شد">
            ممنون که پرسشتان را با ما در میان گذاشتید. کارشناسان نوین‌پی درخواست شما را بررسی می‌کنند و با شما تماس می‌گیرند.
            <x-slot:actions>
                <a class="np-button np-button--primary" href="{{ route('home') }}">بازگشت به صفحهٔ اصلی</a>
                <a class="np-button np-button--outline" href="{{ route('products.index') }}">مشاهدهٔ فروشگاه</a>
            </x-slot:actions>
        </x-status-confirmation>
    </div>
@endsection
