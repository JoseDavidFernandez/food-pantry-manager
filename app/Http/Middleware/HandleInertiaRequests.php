<?php

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
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'household' => $this->household($request->user()),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The user's household, role and permissions (RN-38), so the interface hides what the server
     * would refuse anyway. Null without a household.
     *
     * @return array{id: int, name: string, role: string, role_label: string, can: array{write: bool, manage: bool}}|null
     */
    private function household(mixed $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        $household = $user->households()->first();
        $role = $user->householdRole();

        if ($household === null || $role === null) {
            return null;
        }

        return [
            'id' => $household->id,
            'name' => $household->name,
            'role' => $role->value,
            'role_label' => $role->label(),
            'can' => ['write' => $role->canWrite(), 'manage' => $role->canManage()],
        ];
    }
}
