<?php

// torsade/app/Http/Middleware/HandleInertiaRequests.php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Dates travel in UTC and are displayed in this timezone.
            'timezone' => config('app.display_timezone'),
            // Shown in the sidebar. A visitor who is not signed in learns
            // nothing about the version that is running.
            'appVersion' => $user instanceof User ? config('app.version') : null,
            'auth' => [
                // An explicit list: the model is never serialised as a whole.
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                ] : null,
                // Only decides what the navigation shows. Every route checks
                // the same gates again on the server.
                'can' => [
                    'editNetwork' => $user instanceof User && $user->can('edit-network'),
                    'manageUsers' => $user instanceof User && $user->can('manage-users'),
                    'viewActivityLog' => $user instanceof User && $user->can('view-activity-log'),
                ],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
