<?php

// torsade/app/Services/Home/RecentActivityModule.php

namespace App\Services\Home;

use App\Http\Presenters\ActivityPresenter;
use App\Models\Activity;
use App\Models\User;

/**
 * The latest entries of the activity log, shown as on the page of the
 * log, with the way to it: for those who may read the log only.
 */
final class RecentActivityModule extends HomeModule
{
    public function key(): string
    {
        return 'recent-activity';
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-activity-log');
    }

    public function settings(): array
    {
        return ['limit' => ['min' => 1, 'max' => 50, 'default' => 10]];
    }

    public function data(User $user, array $settings): array
    {
        $entries = Activity::query()
            ->with(['causer', 'subject'])
            ->orderByDesc('id')
            ->limit($settings['limit'])
            ->get();

        return [
            'entries' => array_values($entries->map(fn (Activity $activity): array => ActivityPresenter::entry($activity))->all()),
            'url' => route('activity-log.index'),
        ];
    }
}
