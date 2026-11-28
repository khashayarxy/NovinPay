@extends('layouts.admin')

@section('title', 'ورود مدیر')

@section('content')
    <div class="admin-login-box">
        <div class="admin-auth-card">
            <div class="text-center">
                <x-brand-logo />
                <h1>پنل مدیریت نوین‌پی</h1>
            </div>

            <form method="POST" action="{{ route('admin.login.store') }}" class="mt-6 space-y-4">
                @csrf

                @if ($errors->any())
                    <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert"
                        tabindex="-1" id="error-summary">
                        <ul class="list-disc space-y-1 pe-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div>
                    <label class="label" for="email">ایمیل</label>
                    <input class="input" type="email" id="email" name="email" dir="ltr" value="{{ is_string(old('email')) ? old('email') : '' }}"
                        autocomplete="username" required>
                </div>

                <div>
                    <label class="label" for="password">رمز عبور</label>
                    <input class="input" type="password" id="password" name="password" autocomplete="current-password"
                        required>
                </div>

                <button type="submit" class="btn btn-primary w-full">ورود</button>
            </form>
        </div>
        <a class="admin-text-link block mt-4 text-center" href="{{ route('home') }}">بازگشت به وب‌سایت</a>
    </div>
@endsection
