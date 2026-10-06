<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\UpdateProfilePictureRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $role = $this->profileRole($request);

        return view('profile.edit', [
            'user' => $request->user(), 'roleLabel' => $role === UserRole::Admin ? 'Admin' : 'Resident',
            'navigation' => config('navigation.'.$role->value), 'profileRoutePrefix' => $role->value.'.profile',
            'dashboardDate' => now('Asia/Manila'), 'pageTitle' => 'Profile / Account Settings',
            'pageHeading' => 'Profile / Account Settings', 'pageSection' => 'Account',
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $user = $this->lockedUser($request);
            $user->fill($request->safe()->only(['name', 'contact_number', 'address']))->save();
        });

        return to_route($this->profileRole($request)->value.'.profile.edit')->with('status', 'Your profile information has been updated.');
    }

    public function updatePicture(UpdateProfilePictureRequest $request): RedirectResponse
    {
        $newPath = null;
        try {
            $oldPath = DB::transaction(function () use ($request, &$newPath) {
                $user = $this->lockedUser($request);
                $oldPath = $user->ownedProfilePicturePath();
                $newPath = $request->file('profile_picture')->store('profile-pictures/'.$user->id, 'local');
                if (! $newPath) {
                    throw ValidationException::withMessages(['profile_picture' => 'We could not save your picture. Please try again.']);
                }
                $user->profile_picture = $newPath;
                $user->save();

                return $oldPath;
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $exception;
        }
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return to_route($this->profileRole($request)->value.'.profile.edit')->with('status', 'Your profile picture has been updated.');
    }

    public function destroyPicture(Request $request): RedirectResponse
    {
        $oldPath = DB::transaction(function () use ($request) {
            $user = $this->lockedUser($request);
            $oldPath = $user->ownedProfilePicturePath();
            $user->profile_picture = null;
            $user->save();

            return $oldPath;
        });
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return to_route($this->profileRole($request)->value.'.profile.edit')->with('status', 'Your profile picture has been removed.');
    }

    public function picture(Request $request): StreamedResponse
    {
        return $this->pictureResponse($request->user());
    }

    public function residentPicture(User $user): StreamedResponse
    {
        abort_unless($user->role === UserRole::Resident, 404);

        return $this->pictureResponse($user);
    }

    private function pictureResponse(User $user): StreamedResponse
    {
        $path = $user->ownedProfilePicturePath();
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function lockedUser(Request $request): User
    {
        $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        abort_unless($user->role === $this->profileRole($request) && $user->is_active && $user->hasVerifiedEmail(), 403);

        return $user;
    }

    private function profileRole(Request $request): UserRole
    {
        return $request->routeIs('admin.profile.*') ? UserRole::Admin : UserRole::Resident;
    }
}
