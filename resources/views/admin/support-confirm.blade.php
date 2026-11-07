@extends('layouts.admin')
@section('title', 'ورود به پشتیبانی')
@section('content')
<section class="admin-panel mx-auto max-w-md"><h1>ورود به پشتیبانی</h1><x-admin.errors />
<form method="post" action="{{ route('admin.support.authenticate') }}" class="admin-form">@csrf
<label class="label" for="support-password">گذرواژه مدیریت</label><input id="support-password" class="input" name="current_password" type="password" autocomplete="current-password" required>
<button class="btn btn-primary">ادامه پشتیبانی</button></form></section>
@endsection
