@if ($errors->any())
    <div class="admin-notice admin-notice--error" role="alert" tabindex="-1" id="error-summary"><h2>اطلاعات فرم را بررسی کنید</h2><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
