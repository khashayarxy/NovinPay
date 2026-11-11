@extends('layouts.admin')
@section('title', 'تاریخچهٔ فعالیت')
@section('content')
<header class="admin-page-heading"><div><h1>تاریخچهٔ فعالیت مدیریت</h1><p>رویدادهای مدیریتی به وقت تهران</p></div></header>
<section class="admin-panel"><ul class="admin-activities"><x-admin.activity-list :activities="$activities" /></ul></section><div class="mt-6">{{ $activities->links() }}</div>
@endsection
