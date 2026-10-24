<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\UpdateUserPreferencesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UserPreferencesController extends Controller
{
    public function edit(Request $request): View
    {
        $role = $request->user()->role;

        return view('settings.edit', [
            'roleLabel' => $role === UserRole::Admin ? 'Admin' : 'Resident',
            'navigation' => config('navigation.'.$role->value),
            'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => 'Settings', 'pageHeading' => 'Settings', 'pageSection' => 'Account',
        ]);
    }

    public function update(UpdateUserPreferencesRequest $request): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $data) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->is_active && $user->hasVerifiedEmail()
                && $user->role === $request->user()->role, 403);
            $user->forceFill(['theme_preference' => $data['theme_preference']])->save();
        });
        $request->user()->setAttribute('theme_preference', $data['theme_preference']);

        return to_route($request->user()->role->value.'.settings.edit')
            ->with('status', 'Your appearance preference has been saved.');
    }
}
