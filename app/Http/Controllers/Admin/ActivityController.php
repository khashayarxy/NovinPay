<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivity;
use App\Support\AdminActivityPresenter;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function index(): View
    {
        $activities = AdminActivity::query()->latest('id')->paginate(30);

        return view('admin.activities', [
            'activities' => $activities,
            'activityMeta' => AdminActivityPresenter::meta($activities),
        ]);
    }
}
