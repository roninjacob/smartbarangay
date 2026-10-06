<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FilterUsersRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(FilterUsersRequest $request): View
    {
        $filters = $request->validated();
        $search = $filters['search'] ?? '';
        $users = User::query()->where('role', UserRole::Resident->value)
            ->select(['id', 'name', 'email', 'contact_number', 'is_active', 'email_verified_at', 'created_at'])
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('contact_number', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('is_active', $status === 'active'))
            ->when($filters['verification'] ?? null, function (Builder $query, string $verification) {
                $verification === 'verified' ? $query->whereNotNull('email_verified_at') : $query->whereNull('email_verified_at');
            })
            ->latest()->orderByDesc('id')->paginate(10)->appends($filters);

        return view('admin.users.index', [...$this->shellData('User Management'), 'users' => $users, 'filters' => $filters]);
    }

    public function show(User $user): View
    {
        abort_unless($user->role === UserRole::Resident, 404);

        return view('admin.users.show', [...$this->shellData('Resident Account Details'), 'resident' => $user]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($user, $data) {
            $resident = User::where('role', UserRole::Resident->value)->lockForUpdate()->findOrFail($user->id);
            if ($resident->is_active !== (bool) $data['expected_is_active']) {
                throw ValidationException::withMessages(['is_active' => 'This account status has changed. Review the current status before updating.']);
            }
            // Authorization fields stay guarded against mass assignment. Only this approved action may change active status.
            $resident->is_active = (bool) $data['is_active'];
            $resident->save();
        });

        return to_route('admin.users.show', $user)->with('status', $data['is_active'] ? 'Resident account activated.' : 'Resident account deactivated.');
    }

    private function shellData(string $heading): array
    {
        return ['roleLabel' => 'Admin', 'navigation' => config('navigation.admin'), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => $heading, 'pageHeading' => $heading, 'pageSection' => 'User Management'];
    }
}
