<?php

// torsade/app/Policies/NetworkPolicy.php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The rule shared by every object of the network documentation: any active
 * account reads it, editors and administrators write it. The two gates hold
 * the rule; the policies only say which one applies to which action.
 */
abstract class NetworkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-network');
    }

    public function view(User $user, ?Model $model = null): bool
    {
        return $user->can('view-network');
    }

    public function create(User $user): bool
    {
        return $user->can('edit-network');
    }

    public function update(User $user, ?Model $model = null): bool
    {
        return $user->can('edit-network');
    }

    public function delete(User $user, ?Model $model = null): bool
    {
        return $user->can('edit-network');
    }
}
