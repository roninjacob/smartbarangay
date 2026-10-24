<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        return view('welcome', ['services' => Service::where('is_active', true)->select(['id', 'name', 'description'])
            ->orderBy('name')->orderBy('id')->paginate(12, ['*'], 'services_page')->fragment('services')]);
    }
}
