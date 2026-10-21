<?php

// torsade/app/Services/Home/SearchModule.php

namespace App\Services\Home;

use App\Models\User;

/**
 * The field of the search of the documentation: what is typed in it opens
 * the search window. It has nothing to prepare.
 */
final class SearchModule extends HomeModule
{
    public function key(): string
    {
        return 'search';
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-network');
    }

    public function data(User $user, array $settings): array
    {
        return [];
    }
}
