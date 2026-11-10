<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IotMonitoring;
use Illuminate\Http\Request;

class IotDashboardController extends Controller
{
    public function index(IotMonitoring $monitoring)
    {
        return response()->view('admin.iot.index', [
            'roleLabel' => 'Admin', 'navigation' => config('navigation.admin'),
            'dashboardDate' => now('Asia/Manila'), 'pageTitle' => 'IoT Dashboard',
            'pageHeading' => 'IoT Dashboard', 'pageSection' => 'Device Monitoring',
            'devices' => config('iot.enabled') ? $monitoring->devices() : [],
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function data(Request $request, IotMonitoring $monitoring)
    {
        abort_unless(config('iot.enabled'), 503);
        $data = $request->validate(['device' => ['required', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return response()->json($monitoring->report($data['device'], (int) ($data['page'] ?? 1)))
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
