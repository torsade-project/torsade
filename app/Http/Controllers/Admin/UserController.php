<?php

// torsade/app/Http/Controllers/Admin/UserController.php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserStoreRequest;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Models\User;
use App\Services\UserAccounts;
use App\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User administration. Every route of this controller is behind the
 * "manage-users" gate (see routes/admin.php).
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(50)
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'role_label' => __($user->role->label()),
                'is_active' => $user->is_active,
                'must_change_password' => $user->must_change_password,
                'two_factor' => $user->twoFactorState(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'is_self' => $user->is($request->user()),
            ]);

        return Inertia::render('users/Index', [
            'users' => $users,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('users/Create', [
            'roles' => $this->roles(),
        ]);
    }

    /**
     * Create an account. The temporary password only exists in this response:
     * it is neither stored in clear nor put in the session.
     */
    public function store(UserStoreRequest $request, UserAccounts $accounts): JsonResponse
    {
        [$user, $password] = $accounts->create(
            $request->validated('name'),
            $request->validated('email'),
            Role::from($request->validated('role')),
            Actor::fromRequest($request),
        );

        return response()
            ->json([
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'temporary_password' => $password,
            ], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function edit(Request $request, User $user): Response
    {
        return Inertia::render('users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'is_self' => $user->is($request->user()),
                'two_factor' => $user->twoFactorState(),
                'two_factor_required' => $user->two_factor_required,
            ],
            'roles' => $this->roles(),
        ]);
    }

    public function update(UserUpdateRequest $request, User $user, UserAccounts $accounts): RedirectResponse
    {
        $accounts->update(
            $user,
            $request->validated('name'),
            $request->validated('email'),
            Role::from($request->validated('role')),
            Actor::fromRequest($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('users.index');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function roles(): array
    {
        return array_map(
            fn (Role $role): array => ['value' => $role->value, 'label' => __($role->label())],
            Role::cases(),
        );
    }
}
