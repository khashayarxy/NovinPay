@extends('layouts.app')
@section('title', 'روش تحویل')
@section('content')
<div class="container py-8"><section class="public-panel mx-auto max-w-2xl"><h1>روش تحویل</h1><x-admin.errors />
@if(session('success'))<p role="status">{{ session('success') }}</p>@endif
@if($plan && $plan->state !== 'planned')<p>تحویل دستگاه ثبت شده است؛ روش تحویل دیگر قابل تغییر نیست.</p><p>{{ $plan->tracking }}</p>
@else<form method="post" action="{{ route('delivery.request',$document->public_id) }}" class="space-y-4">@csrf<input type="hidden" name="version" value="{{ $plan?->version ?? 0 }}"><fieldset><legend>روش تحویل را انتخاب کنید</legend><label class="flex items-center gap-3 py-3"><input type="radio" name="choice" value="shipping" required> ارسال به آدرس شما</label><label class="flex items-center gap-3 py-3"><input type="radio" name="choice" value="pickup" required> تحویل حضوری در فروشگاه ما</label></fieldset>
<label class="label" for="delivery-carrier">برای ارسال به آدرس شما</label><select class="input" id="delivery-carrier" name="carrier"><option value="tipax">تیپاکس؛ کرایه هنگام تحویل به شرکت حمل پرداخت می‌شود</option><option value="courier">پیک؛ کرایه مستقیم به پیک پرداخت می‌شود</option><option value="post" disabled>پست فعلاً در دسترس نیست</option></select>
<label class="label" for="delivery-address">آدرس ارسال</label><select class="input" id="delivery-address" name="address"><option value="">برای تحویل حضوری لازم نیست</option>@foreach($addresses as $address)<option value="{{ $address->public_id }}">{{ $address->province }}، {{ $address->city }}، {{ $address->address }}</option>@endforeach</select><p>تحویل حضوری هزینهٔ ارسال ندارد. کرایهٔ تیپاکس و پیک به صورتحساب فروشگاه اضافه نمی‌شود.</p><p>تغییر روش فقط پیش از تحویل واقعی و پس از تأیید همکاران اعمال می‌شود.</p><button class="btn btn-primary">ثبت روش تحویل</button></form>@endif
<a class="public-inline-link block mt-6" href="{{ route('payments.document',$document->public_id) }}">بازگشت به سند و پرداخت</a>
</section></div>
@endsection
