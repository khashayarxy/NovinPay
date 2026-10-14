@extends('layouts.admin')

@section('title', 'ورود مدیر')

@section('content')
    <div class="mx-auto max-w-md">
        <div class="card p-6 sm:p-8">
            <h1 class="text-xl font-bold">ورود به پنل مدیریت</h1>
            <p class="mt-2 text-sm leading-7 text-slate-600">
                برای مدیریت محصولات وارد شوید. ثبت‌نام عمومی در این وب‌سایت وجود ندارد.
            </p>

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
                    <input class="input" type="email" id="email" name="email" value="{{ old('email') }}"
                        autocomplete="username" required autofocus aria-describedby="email-hint">
                    <p class="mt-1 text-xs text-slate-500" id="email-hint">ایمیل مدیریت حساب</p>
                </div>

                <div>
                    <label class="label" for="password">گذرواژه</label>
                    <input class="input" type="password" id="password" name="password" autocomplete="current-password"
                        required>
                </div>

                <button type="submit" class="btn btn-primary w-full">ورود</button>
            </form>
        </div>
    </div>
@endsection
