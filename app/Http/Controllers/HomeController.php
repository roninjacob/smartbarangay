<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $active = Service::where('is_active', true);

        return view('welcome', [
            'services' => (clone $active)->select(['id', 'name', 'description', 'fee_amount'])
                ->withCount('serviceRequirements')
                ->orderBy('name')->orderBy('id')->paginate(12, ['*'], 'services_page')->fragment('services'),
            'activeServiceCount' => (clone $active)->count(),
        ]);
    }
}
