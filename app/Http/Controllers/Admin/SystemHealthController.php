<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Observability\SystemHealthService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SystemHealthController extends Controller
{
    public function index(Request $request, SystemHealthService $health): View
    {
        $channel = is_string($request->query('channel')) ? $request->query('channel') : 'daily';
        $level = is_string($request->query('level')) ? $request->query('level') : 'ALL';

        return view('admin.system-health.index', [
            'checks' => $health->checks(),
            'logs' => $health->recentLogs($channel, $level, 80),
            'levels' => SystemHealthService::LEVELS,
        ]);
    }
}
