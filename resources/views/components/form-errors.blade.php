@if ($errors->any())
    <div class="public-notice public-notice--error" role="alert" tabindex="-1" id="form-errors">
        <h2>ارسال انجام نشد؛ موارد زیر را بررسی کنید</h2>
        <ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        <p>برای حفظ حریم خصوصی، اطلاعات واردشده در فرم نگه‌داری نمی‌شود.</p>
    </div>
@endif
