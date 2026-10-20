<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivity;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function index(): View
    {
        return view('admin.activities', ['activities' => AdminActivity::query()->latest('id')->paginate(30)]);
    }
}
